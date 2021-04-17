<?php

namespace App\Command;

use ErrorException;
use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WikidataCommand extends Command
{
  protected static $defaultName = 'process:wikidata';

  protected const URL = 'https://www.wikidata.org/wiki/Special:EntityData/';

  protected string $relationPath = 'data/overpass/relation.json';
  protected string $wayPath = 'data/overpass/way.json';
  protected string $outputDir = 'data/wikidata';
  protected array $elements = [];

  protected function configure()
  {
    $this->setDescription('Download data from Wikidata.');
  }

  protected function initialize(InputInterface $input, OutputInterface $output)
  {
    $output->getFormatter()->setStyle('warning', new OutputFormatterStyle('red'));

    if (!file_exists($this->outputDir) || !is_dir($this->outputDir)) {
      mkdir($this->outputDir, 0777, true);
    }
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    try {
      if (!file_exists($this->relationPath) || !is_readable($this->relationPath)) {
        throw new ErrorException(sprintf('File "%s" doesn\'t exist or is not readable. You maybe need to run "process:overpass" command first.', $this->relationPath));
      }
      if (!file_exists($this->wayPath) || !is_readable($this->wayPath)) {
        throw new ErrorException(sprintf('File "%s" doesn\'t exist or is not readable. You maybe need to run "process:overpass" command first.', $this->relationPath));
      }

      $output->writeln(sprintf('<info>%s</info>', $this->getDescription()));

      $relations = json_decode(file_get_contents($this->relationPath));
      $ways = json_decode(file_get_contents($this->wayPath));

      // Only keep ways/relations that have a `wikidata` tag and/or a `name:etymology:wikidata` tag
      $this->elements = array_filter(
        array_merge($relations->elements ?? [], $ways->elements ?? []),
        function ($element): bool {
          return isset($element->tags) &&
            (isset($element->tags->wikidata) || isset($element->tags->{'name:etymology:wikidata'}));
        }
      );

      $progressBar = new ProgressBar($output, count($this->elements));
      $progressBar->start();

      foreach ($this->elements as $element) {
        $wikidataTag = $element->tags->wikidata ?? null;
        $etymologyTag = $element->tags->{'name:etymology:wikidata'} ?? null;

        if (!is_null($etymologyTag)) {
          $identifiers = explode(';', $etymologyTag);
          $identifiers = array_map('trim', $identifiers);

          foreach ($identifiers as $identifier) {
              // Check that the value of the tag is a valid Wikidata item identifier
              if (preg_match('/^Q.+$/', $identifier) !== 1) {
                throw new Exception(sprintf('Format of `name:etymology:wikidata` is invalid (%s) for %s(%d).%s', $identifier, $element['type'], $element['id']));
              }

              // Download Wikidata item
              $path = sprintf('%s/%s.json', $this->outputDir, $identifier);
              if (!file_exists($path)) {
                $wikidata = self::query($identifier, $element);

                file_put_contents($path, $wikidata);
              }
          }
        }

        if (!is_null($wikidataTag)) {
          // Download Wikidata item
          // $path = sprintf('%s/%s.json', $this->outputDir, $wikidataTag);
          // if (!file_exists($path)) {
          //   $wikidata = self::query($wikidataTag, $element);

          //   file_put_contents($path, $wikidata);
          // }

          // @todo Extract "named after" (P138) property
        }


        $progressBar->advance();
      }

      $progressBar->finish();

      $output->writeln('');

      return Command::SUCCESS;
    } catch (ErrorException $error) {
      $output->writeln(sprintf('<error>%s</error>', $error->getMessage()));

      return Command::FAILURE;
    } catch (Exception $exception) {
      $output->writeln(sprintf('<warning>%s</warning>', $exception->getMessage()));
    }
  }

  protected static function query(string $identifier, $element): ?string
  {
    $url = sprintf('%s%s.json', self::URL, $identifier);

    try {
      $client = new \GuzzleHttp\Client();
      $response = $client->request('GET', $url);

      return (string) $response->getBody();
    } catch (BadResponseException $exception) {
      switch ($exception->getCode()) {
        case 404:
          throw new Exception(sprintf('Wikidata item %s for %s(%d) does not exist.', $identifier, $element['type'], $element['id']));
          break;
        default:
          throw new Exception(sprintf('Error while fetching Wikidata item %s for %s(%d): %s.', $identifier, $element['type'], $element['id'], $exception->getMessage()));
          break;
      }

      return null;
    }
  }
}
