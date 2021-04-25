<?php

namespace App\Command;

use App\Wikidata\Wikidata;
use ErrorException;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GeoJSONCommand extends AbstractCommand
{
    protected static $defaultName = 'geojson';

    protected array $csv;

    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('Generate GeoJSON files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            parent::execute($input, $output);

            // Brussels only (for now)
            if ($this->city === 'belgium/brussels') {
                $csvPath = sprintf('%s/event-2020-02-17/gender.csv', $this->cityDir);
                if (file_exists($csvPath) && is_readable($csvPath)) {
                    $this->csv = [];
                    $handle = fopen(sprintf('%s/event-2020-02-17/gender.csv', $this->cityDir), 'r');
                    if ($handle !== false) {
                        while (($data = fgetcsv($handle)) !== false) {
                            $streetFR = $data[0];
                            $streetNL = $data[1];
                            $gender = $data[2];

                            if (isset($this->csv[md5($streetFR)]) && $this->csv[md5($streetFR)] !== $gender) {
                                throw new ErrorException('');
                            }
                            if (isset($this->csv[md5($streetNL)]) && $this->csv[md5($streetNL)] !== $gender) {
                                throw new ErrorException('');
                            }

                            $this->csv[md5($streetFR)] = $gender;
                            $this->csv[md5($streetNL)] = $gender;
                        }
                        fclose($handle);
                    }
                }
            }

            $relationPath = sprintf('%s/overpass/relation.json', $this->processOutputDir);
            if (!file_exists($relationPath) || !is_readable($relationPath)) {
                throw new ErrorException(sprintf('File "%s" doesn\'t exist or is not readable. You maybe need to run "overpass" command first.', $relationPath));
            }
            $wayPath = sprintf('%s/overpass/way.json', $this->processOutputDir);
            if (!file_exists($wayPath) || !is_readable($wayPath)) {
                throw new ErrorException(sprintf('File "%s" doesn\'t exist or is not readable. You maybe need to run "overpass" command first.', $wayPath));
            }

            $contentR = file_get_contents($relationPath);
            $overpassR = $contentR !== false ? json_decode($contentR) : null;
            $contentW = file_get_contents($wayPath);
            $overpassW = $contentW !== false ? json_decode($contentW) : null;

            $output->write('Relations: ');
            $geojsonR = $this->createGeoJSON('relation', $overpassR->elements ?? [], $output);
            $output->write('Ways: ');
            $geojsonW = $this->createGeoJSON('way', $overpassW->elements ?? [], $output);

            if (isset($this->config['exclude'], $this->config['exclude']['relation']) && is_array($this->config['exclude']['relation'])) {
                $geojsonR['features'] = array_filter($geojsonR['features'], function ($feature): bool {
                    return !in_array($feature['id'], $this->config['exclude']['relation'], true);
                });
            }
            if (isset($this->config['exclude'], $this->config['exclude']['way']) && is_array($this->config['exclude']['way'])) {
                $geojsonW['features'] = array_filter($geojsonW['features'], function ($feature): bool {
                    return !in_array($feature['id'], $this->config['exclude']['way'], true);
                });
            }

            file_put_contents(
                sprintf('%s/relations.geojson', $this->cityOutputDir),
                json_encode($geojsonR)
            );
            file_put_contents(
                sprintf('%s/ways.geojson', $this->cityOutputDir),
                json_encode($geojsonW)
            );

            return Command::SUCCESS;
        } catch (Exception $error) {
            $output->writeln(sprintf('<error>%s</error>', $error->getMessage()));

            return Command::FAILURE;
        }
    }

    private static function extractElements(string $type, array $elements): array
    {
        $filter = array_filter(
            $elements,
            function ($element) use ($type): bool {
                return $element->type === $type;
            }
        );

        $result = [];

        foreach ($filter as $f) {
            $result[$f->id] = $f;
        }

        return $result;
    }

    private static function extractDetails($entity, array $config, array &$warnings = []): ?array
    {
        $dateOfBirth = Wikidata::extractDateOfBirth($entity);
        $dateOfDeath = Wikidata::extractDateOfDeath($entity);

        $person = Wikidata::isPerson($entity, $config['instances']);
        if (is_null($person)) {
            $warnings[] = sprintf('No instance or subclass for "%s".', $entity->id);
            $person = false;
        }

        return [
            'wikidata'     => $entity->id,
            'person'       => $person,
            'gender'       => Wikidata::extractGender($entity),
            'labels'       => Wikidata::extractLabels($entity, $config['languages']),
            'descriptions' => Wikidata::extractDescriptions($entity, $config['languages']),
            'nicknames'    => Wikidata::extractNicknames($entity, $config['languages']),
            'birth'        => is_null($dateOfBirth) ? null : intval(substr($dateOfBirth, 0, 5)),
            'death'        => is_null($dateOfDeath) ? null : intval(substr($dateOfDeath, 0, 5)),
            'sitelinks'    => Wikidata::extractSitelinks($entity, $config['languages']),
            'image'        => Wikidata::extractImage($entity),
        ];
    }

    private function createProperties($object, array &$warnings = []): array
    {
        $properties = [
            'name'     => $object->tags->name ?? null,
            'wikidata' => $object->tags->wikidata ?? null,
            'source'   => null,
            'gender'   => null,
            'details'  => null,
        ];

        if (isset($object->tags->{'name:etymology:wikidata'})) {
            $identifiers = explode(';', $object->tags->{'name:etymology:wikidata'});
            $identifiers = array_map('trim', $identifiers);

            $details = [];
            foreach ($identifiers as $identifier) {
                $wikiPath = sprintf('%s/wikidata/%s.json', $this->processOutputDir, $identifier);
                if (!file_exists($wikiPath) || !is_readable($wikiPath)) {
                    $warnings[] = sprintf('<warning>File "%s" doesn\'t exist or is not readable (tagged in %s(%s)). You maybe need to run "wikidata" command first.</warning>', $wikiPath, $object->type, $object->id);
                } else {
                    $content = file_get_contents($wikiPath);
                    $json = $content !== false ? json_decode($content) : null;
                    if (is_null($json)) {
                        throw new ErrorException(sprintf('Can\'t read "%s".', $wikiPath));
                    }
                    $entity = current($json->entities);

                    if ($entity->id !== $identifier) {
                        $warnings[] = sprintf('Entity "%s" is (probably) redirected to "%s" (tagged in %s(%s)).', $identifier, $entity->id, $object->type, $object->id);
                    }

                    $details[] = self::extractDetails($entity, $this->config ?? [], $warnings);
                }
            }

            $_person = array_unique(array_column($details, 'person'));
            $_gender = array_unique(array_column($details, 'gender'));

            $gender = (count($_person) === 1 && current($_person) === true) ? (count($_gender) === 1 ? current($_gender) : '+') : null;

            if (count($details) === 1) {
                $details = current($details);
            }

            $properties['source'] = 'wikidata';
            $properties['gender'] = $gender;
            $properties['details'] = $details;
        } elseif (
            isset(
                $this->config['gender'],
                $this->config['gender'][$object->type],
                $this->config['gender'][$object->type][(string) $object->id]
            )
        ) {
            $properties['source'] = 'config';
            $properties['gender'] = $this->config['gender'][$object->type][(string) $object->id];
        } elseif (isset($this->csv) && count($this->csv) > 0) {
            if (isset($object->tags->{'name:fr'}, $this->csv[md5($object->tags->{'name:fr'})])) {
                $properties['source'] = 'event';
                $properties['gender'] = $this->csv[md5($object->tags->{'name:fr'})];
            } elseif (isset($object->tags->{'name:nl'}, $this->csv[md5($object->tags->{'name:nl'})])) {
                $properties['source'] = 'event';
                $properties['gender'] = $this->csv[md5($object->tags->{'name:nl'})];
            } elseif (isset($object->tags->{'name'}, $this->csv[md5($object->tags->{'name'})])) {
                $properties['source'] = 'event';
                $properties['gender'] = $this->csv[md5($object->tags->{'name'})];
            }
        }

        return $properties;
    }

    private static function createGeometry($object, array $relations, array $ways, array $nodes, array &$warnings = []): ?array
    {
        $linestrings = [];

        if ($object->type === 'relation') {
            $members = array_filter(
                $object->members,
                function ($member): bool {
                    return $member->role === 'street' || $member->role === 'outer';
                }
            );

            if (count($members) === 0) {
                $warnings[] = sprintf('No "street" or "outer" member in relation(%d).</warning>', $object->id);
            } else {
                foreach ($members as $member) {
                    if ($member->type === 'relation') {
                        if (isset($relations[$member->ref])) {
                            $linestrings[] = self::createGeometry($relations[$member->ref], $relations, $ways, $nodes, $warnings);
                        } else {
                            $linestrings[] = sprintf('<warning>Can\'t find relation(%d) in relation(%d).</warning>', $member->ref, $object->id);
                        }
                    } elseif ($member->type === 'way') {
                        if (isset($ways[$member->ref])) {
                            $linestrings[] = self::createGeometry($ways[$member->ref], $relations, $ways, $nodes, $warnings);
                        } else {
                            $linestrings[] = sprintf('<warning>Can\'t find way(%d) in relation(%d).</warning>', $member->ref, $object->id);
                        }
                    }
                }
            }
        } elseif ($object->type === 'way') {
            foreach ($object->nodes as $id) {
                $node = $nodes[$id] ?? null;

                if (is_null($node)) {
                    $warnings[] = sprintf('<warning>Can\'t find node(%d) in way(%d).</warning>', $id, $object->id);
                } else {
                    $linestrings[] = [$node->lon, $node->lat];
                }
            }
        }

        if (count($linestrings) === 0) {
            $warnings[] = sprintf('<warning>No geometry for %s(%d).</warning>', $object->type, $object->id);

            return null;
        } elseif (count($linestrings) > 1) {
            return [
                'type'        => 'MultiLineString',
                'coordinates' => $linestrings,
            ];
        } else {
            return [
                'type'        => 'LineString',
                'coordinates' => $linestrings[0],
            ];
        }
    }

    private function createGeoJSON(string $type, array $elements, OutputInterface $output)
    {
        $nodes = self::extractElements('node', $elements);
        $ways = self::extractElements('way', $elements);
        $relations = self::extractElements('relation', $elements);

        $output->writeln(sprintf('%d node(s), %d way(s), %d relation(s)', count($nodes), count($ways), count($relations)));

        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [],
        ];

        $objects = $type === 'relation' ? $relations : $ways;

        $warnings = [];
        $progressBar = new ProgressBar($output, count($objects));
        $progressBar->start();

        foreach ($objects as $object) {
            $properties = $this->createProperties($object, $warnings);
            $geometry = self::createGeometry($object, $relations, $ways, $nodes, $warnings);

            $geojson['features'][] = [
                'type' => 'Feature',
                'id'   => $object->id,
                'properties' => $properties,
                'geometry' => $geometry,
            ];

            $progressBar->advance();
        }

        $progressBar->finish();

        $output->writeln(['', ...$warnings]);

        return $geojson;
    }
}
