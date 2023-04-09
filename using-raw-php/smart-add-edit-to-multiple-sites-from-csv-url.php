<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles to multiple Joomla Sites Via API Using Streamed CSV
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 *
 * @author        Alexandre ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license       GPL-2.0-and-later GNU General Public License v2.0 or later
 * @link          https://apiadept.com
 */

// Public url of the sample csv used in this example (CHANGE WITH YOUR OWN CSV URL IF YOU WISH)
$csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTlM7un4cv3t5oKQ6mymmBDrAnbpFcYLFh6KnHMC6iCE8qUJSNKJ4Vw54r4OjGNNU4DXxOuLWdtKvZ2/pub?output=csv';

// Your Joomla! 4.x website base url
$baseUrl = [
    'app-001' => 'https://app-001.example.org',
    'app-002' => 'https://app-002.example.org',
    'app-003' => 'https://app-003.example.org',
];
// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token = [
    'app-001' => 'yourapp001token',
    'app-002' => 'yourapp002token',
    'app-003' => 'yourapp003token',
];
$basePath = 'api/index.php/v1';

// Request timeout
$timeout = 10;

// Add custom fields support (shout-out to Marc DECHÈVRE : CUSTOM KING)
// The keys are the columns in the csv with the custom fields names (that's how Joomla! Web Services Api work as of today)
// For the custom fields to work they need to be added in the csv and to exists in the Joomla! site.
$customFieldKeys = []; //['with-coffee','with-dessert','extra-water-bottle'];

// Silent mode. (If set to true, no messages displayed on the screen while processing data and it might improve performance)
$silent = true;

// Line numbers we want in any order (e.g 9,7-7,2-4,10,17-14,21). Leave empty '' to process all lines (beginning at line 2. Same as csv file)
$whatLineNumbersYouWant = '';

$computedLineNumbers = function (string $wantedLineNumbers = '') {
    if ($wantedLineNumbers === '') {
        return [];
    }
    // Don't match more than 10 "groups" separated by commas
    $commaParts = explode(',', $wantedLineNumbers);
    if (!$commaParts) {
        return [];
    }
    asort($commaParts, SORT_NATURAL);
    $output = [];
    foreach ($commaParts as $commaPart) {
        if (strpos($commaPart, '-') === false) {
            $output[] = (int)$commaPart;
            // Skip to next comma part
            continue;
        }
        // maximum 1 dash "group" per comma separated "groups"
        $dashParts = explode('-', $commaPart, 2);
        if (!$dashParts) {
            $output[] = (int)$commaPart;
            // Skip to next comma part
            continue;
        }
        $dashParts[0] = (int)$dashParts[0];
        $dashParts[1] = (int)$dashParts[1];
        // Only store one digit if both are the same in the range
        if ($dashParts[0] === $dashParts[1]) {
            $output[] = $dashParts[0];
        } elseif ($dashParts[0] > $dashParts[1]) {
            // Store expanded range of numbers
            $output = array_merge($output, range($dashParts[1], $dashParts[0]));
        } else {
            // Store expanded range of numbers
            $output = array_merge($output, range($dashParts[0], $dashParts[1]));
        }
    }

    return array_unique($output, SORT_NUMERIC);
};

// This time we need endpoint to be a function to make it more dynamic
$endpoint = function (string $givenBaseUrl, string $givenBasePath, int $givenResourceId = 0): string {
    return $givenResourceId ? sprintf('%s/%s/%s/%d', $givenBaseUrl, $givenBasePath, 'content/articles', $givenResourceId)
        : sprintf('%s/%s/%s', $givenBaseUrl, $givenBasePath, 'content/articles');
};

// handle nested json
$nested = function (array $arr, bool $isSilent = false): array {
    $handleComplexValues = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($arr), RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($iterator as $key => $value) {
        if (mb_strpos($value, '{') === 0) {
            echo $isSilent ? '' : 'current item key: ' . $key . ' with value ' . $value . PHP_EOL;
            // Doesn't seem to make sense at first but this one line allows to show intro/fulltext images and urla,urlb,urlc
            $handleComplexValues[$key] = json_decode(str_replace(["\n", "\r", "\t"], '', trim($value)));
        } elseif (json_decode($value) === false) {
            $handleComplexValues[$key] = json_encode($value);
            echo $isSilent ? '' : 'current item key: ' . $key . ' with value ' . $value . PHP_EOL;
        } else {
            $handleComplexValues[$key] = $value;
            echo $isSilent ? '' : 'current item key: ' . $key . ' with value ' . $value . PHP_EOL;
        }
    }

    return $handleComplexValues;
};

// PHP Generator to efficiently read the csv file
$generator = function (string $url, array $keys, callable $givenNested, bool $isSilent = false, array $lineRange = []): Generator {
    if (empty($url)) {
        yield new RuntimeException('Url MUST NOT be empty', 422);
    }

    $defaultKeys = [
        'id',
        'access',
        'title',
        'alias',
        'catid',
        'articletext',
        'introtext',
        'fulltext',
        'language',
        'metadesc',
        'metakey',
        'state',
        'featured',
        'images',
        'urls',
        'tokenindex',
    ];

    $mergedKeys = empty($keys) ? $defaultKeys : array_unique(array_merge($defaultKeys, $keys));

    // Assess robustness of the code by trying random key order
    //shuffle($mergedKeys);

    $resource = fopen($url, 'r');

    if ($resource === false) {
        yield new RuntimeException('Could not read csv file', 500);
    }

    try {
        stream_set_blocking($resource, false);

        $firstLine = stream_get_line(
            $resource,
            0,
            "\r\n"
        );

        if (!is_string($firstLine) || empty($firstLine)) {
            yield new RuntimeException('First line MUST NOT be empty. It is the header', 422);
        }

        $csvHeaderKeys = str_getcsv($firstLine);
        $commonKeys = array_intersect($csvHeaderKeys, $mergedKeys);

        $isExpanded = ($lineRange !== []);
        $currentCsvLineNumber = 1;
        if ($isExpanded) {
            $maxLineNumber = max($lineRange);
        }

        do {
            $currentLine = stream_get_line(
                $resource,
                0,
                "\r\n"
            );
            $currentCsvLineNumber += 1;
            if (!is_string($currentLine) || empty($currentLine)) {
                continue;
            }

            $extractedContent = str_getcsv($currentLine);

            // Allow using csv keys in any order
            $commonValues = array_intersect_key($extractedContent, $commonKeys);

            // Iteration on leafs AND nodes
            $handleComplexValues = $givenNested($commonValues, $isSilent);
            $encodedContent = json_encode(array_combine($commonKeys, $handleComplexValues));

            // Last line number in range has been reached. Stop here
            if ($isExpanded && ($currentCsvLineNumber > $maxLineNumber + 1)) {
                break;
            }

            if ($encodedContent === false) {
                yield new RuntimeException('Current line seem to be invalid', 422);
            } elseif (is_string($encodedContent) && (($isExpanded && in_array($currentCsvLineNumber, $lineRange, true)) || !$isExpanded)) {
                yield $encodedContent;
            }
        } while (!feof($resource));
    } finally {
        fclose($resource);
    }
};

// Process data returned by the PHP Generator
$process = function (string $givenHttpVerb, string $endpoint, string $dataString, array $headers, int $timeout, $transport) {
    curl_setopt_array($transport, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_CUSTOMREQUEST => $givenHttpVerb,
            CURLOPT_POSTFIELDS => $dataString,
            CURLOPT_HTTPHEADER => $headers,
        ]
    );

    $response = curl_exec($transport);
    // Continue even on partial failure
    if (empty($response)) {
        throw new RuntimeException('Empty output', 422);
    }

    return $response;
};
$expandedLineNumbers = $computedLineNumbers($whatLineNumbersYouWant);
$streamCsv = $generator($csvUrl, $customFieldKeys, $nested, $silent, $expandedLineNumbers);
$storage = [];

foreach ($streamCsv as $dataKey => $dataString) {
    $curl = curl_init();
    try {
        if (!is_string($dataString)) {
            continue;
        }

        $decodedDataString = json_decode($dataString);
        if ($decodedDataString === false) {
            continue;
        }

        // HTTP request headers
        $headers = [
            'Accept: application/vnd.api+json',
            'Content-Type: application/json',
            'Content-Length: ' . mb_strlen($dataString),
            sprintf('X-Joomla-Token: %s', trim($token[$decodedDataString->tokenindex])),
        ];

        // Article primary key. Usually 'id'
        $pk = (int)$decodedDataString->id;


        $output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$decodedDataString->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl);

        $decodedJsonOutput = json_decode($output);

        // don't show errors, handle them gracefully
        if (isset($decodedJsonOutput->errors)) {
            // If article is potentially a duplicate (already exists with same alias)
            $storage[$dataKey] = ['mightExists' => $decodedJsonOutput->errors[0]->code === 400, 'decodedDataString' => $decodedDataString,];
            continue;
        }
    } catch (Throwable $e) {
        echo $silent ? '' : sprintf('Message: %s, Line: %d%s', $e->getMessage(), $e->getLine(), PHP_EOL);
        continue;
    } finally {
        curl_close($curl);
    }
}
// Handle errors and retries
foreach ($storage as $dataKeyRetry => $item) {
    $curl = curl_init();
    try {
        if ($item['mightExists']) {
            $pk = (int)$item['decodedDataString']->id;
            $item['decodedDataString']->alias = sprintf('%s-%s', $item['decodedDataString']->alias, bin2hex(random_bytes(4)));

            $dataString = json_encode($item['decodedDataString']);

            if (!is_string($dataString)) {
                continue;
            }

            // HTTP request headers
            $headers = [
                'Accept: application/vnd.api+json',
                'Content-Type: application/json',
                'Content-Length: ' . mb_strlen($dataString),
                sprintf('X-Joomla-Token: %s', trim($token[$item['decodedDataString']->tokenindex])),
            ];
            $output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$item['decodedDataString']->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl);
            echo $silent ? '' : $output . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo $silent ? '' : sprintf('Message: %s, Line: %d%s', $e->getMessage(), $e->getLine(), PHP_EOL);
        continue;
    } finally {
        curl_close($curl);
    }
}
