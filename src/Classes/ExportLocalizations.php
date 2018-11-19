<?php

namespace KgBot\LaravelLocalization\Classes;

use Illuminate\Support\Facades\Cache;
use KgBot\LaravelLocalization\Events\LaravelLocalizationExported;

class ExportLocalizations implements \JsonSerializable
{
    /**
     * @var array
     */
    protected $strings = [];

    /**
     * @var string
     */
    protected $phpRegex = '/^.+\.php$/i';

    /**
     * @var string
     */
    protected $vendorPath = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

    /**
     * @var string
     */
    protected $packageSeparator = '.';

    /**
     * Method to return generate array with contents of parsed language files.
     *
     * @return object
     */
    public function export()
    {
        // Check if value is cached and set array to cached version
        if (Cache::has(config('laravel-localization.caches.key'))) {
            $this->strings = Cache::get(config('laravel-localization.caches.key'));

            return $this;
        }

        // Collect language files and build array with translations
        $files = $this->findLanguageFiles(resource_path('lang'));

        // Parse translations and create final array
        array_walk($files['vendor'], [$this, 'parseVendorFiles']);
        array_walk($files['lang'], [$this, 'parseLangFiles']);

        // Trigger event for final translated array
        event(new LaravelLocalizationExported($this->strings));

        // If timeout > 0 save array to cache
        if (config('laravel-localization.caches.timeout', 0) > 0) {
            Cache::store(config('laravel-localization.caches.driver', 'file'))
                ->put(
                    config('laravel-localization.caches.key', 'localization.array'),
                    $this->strings,
                    config('laravel-localization.caches.timeout', 60)
                );
        }

        return $this;
    }

    /**
     * Find available language files and parse them to array.
     *
     * @param string $path
     *
     * @return array
     */
    protected function findLanguageFiles($path)
    {
        // Loop through directories
        $dirIterator = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $recIterator = new \RecursiveIteratorIterator($dirIterator);

        // Fetch only php files - skip others
        $phpFiles = array_values(
            array_map('current',
                iterator_to_array(
                    new \RegexIterator($recIterator, $this->phpRegex, \RecursiveRegexIterator::GET_MATCH)
                )
            )
        );

        // Sort array by filepath
        // sort($phpFiles);

        // Remove full path from items
        array_walk($phpFiles, function (&$item) {
            $item = str_replace(resource_path('lang'), '', $item);
        });

        // Fetch non-vendor files from filtered php files
        $nonVendorFiles = array_filter($phpFiles, function ($file) {
            return strpos($file, $this->vendorPath) === false;
        });

        // Fetch vendor files from filtered php files
        $vendorFiles = array_diff($phpFiles, $nonVendorFiles);

        return [
            'lang' => array_values($nonVendorFiles),
            'vendor' => array_values($vendorFiles),
        ];
    }

    /**
     * Method to return array for json serialization.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->strings;
    }

    /**
     * If you need special format of array that's recognised by some npm localization packages as Lang.js
     * https://github.com/rmariuzzo/Lang.js use this method.
     *
     * @param array $array
     * @param string $prefix
     *
     * @return array
     */
    public function toFlat($prefix = '.')
    {
        $results = [];
        foreach ($this->strings as $lang => $strings) {
            foreach ($strings as $lang_array => $lang_messages) {
                $key = $lang . $prefix . $lang_array;
                $results[$key] = $lang_messages;
            }
        }

        return $results;
    }

    /**
     * Method to parse language files from vendor folder.
     *
     * @param string $file
     */
    protected function parseVendorFiles($file)
    {
        self::parseFile($file, true);
    }

    /**
     * Method to parse language files.
     *
     * @param string $file
     */
    protected function parseLangFiles($file)
    {
        self::parseFile($file);
    }

    protected function parseFile($file, $isVendorFile = false)
    {
        // Offset 1: Removes '/' from path
        // Offset 3: Removes '/vendor/packageName' from path
        $offset = $isVendorFile ? 3 : 1;

        $includeFolderPathInStructure = config('laravel-localization.parser.includeFolderPathInStructure');
        $format = config('laravel-localization.parser.format');

        // The following is the schematic of the explode
        // 0/_______1_______/_______x______/__basename()__
        //  /<language_code>/<subFolder>.../<filename>.php
        $fileExplode = explode(DIRECTORY_SEPARATOR, $file);

        // Get language code of package
        $language = $fileExplode[$offset];

        // Set package path to language
        $packagePath = [$language];

        // Get package file contents
        $fileContents = require resource_path('lang') . DIRECTORY_SEPARATOR . $file;

        // If package path should include hole file path
        if ($includeFolderPathInStructure) {
            $packagePath = array_slice($fileExplode, $offset, count($fileExplode) - 1 - $offset);
        }

        if ($format === 'normal') {

            // Base package name without file ending
            $packageName = basename($file, '.php');

            // Get the packages path array
            $packagePathArray = &self::flatCall($this->strings, $packagePath);

        } else if ($format === 'lang.js') {
            // Set package name to: 'lang.path.packageName'
            $packageName = implode('.', $packagePath) . '.' . basename($file, '.php');

            // Set $packagePathArray to root array
            $packagePathArray = &$this->strings;
        }

        // Set content to the files content
        if (array_key_exists($packageName, $packagePathArray)) {
            foreach ($fileContents as $key => $value) {
                $packagePathArray[$packageName][$key] = $value;
            }
        } else {
            $packagePathArray[$packageName] = $fileContents;
        }
    }

    /**
     * Method returns the array of the provided path and creates all path arrays
     * if they don't exist
     *
     * @param $data_arr
     * @param $data_arr_call
     * @return mixed
     */
    function &flatCall(&$data_arr, $data_arr_call)
    {
        $current = &$data_arr;
        foreach ($data_arr_call as $key) {
            if (!array_key_exists($key, $current)) $current[$key] = [];
            $current = &$current[$key];
        }
        return $current;
    }

    /**
     * Method to return array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->strings;
    }
}
