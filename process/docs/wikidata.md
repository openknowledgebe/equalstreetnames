# EqualStreetNames - Scripts

## Scripts

- [`WikidataCommand.php`](../Command/WikidataCommand.php)

## Download data from [_Wikidata_](https://www.wikidata.org/)

For every `associatedStreet` relations and `highway` ways that have a [`wikidata` tag](https://wiki.openstreetmap.org/wiki/Key:wikidata) or a [`name:etymology:wikidata` tag](https://wiki.openstreetmap.org/wiki/Key:name:etymology:wikidata), we download the data from _Wikidata_ (JSON format).

## Run the script locally

```cmd
composer install

php process.php wikidata
```

The JSON files will be stored in `data/wikidata/` directory.
