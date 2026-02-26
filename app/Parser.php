<?php

namespace App;

use Exception;

final class Parser
{
    const int PREFIX_LENGTH = 19;
    const int TIME_LENGTH = -16;
    const string DELIMITER = ',';

    public function parse(string $inputPath, string $outputPath): void
    {
        gc_disable();

        $file = fopen($inputPath, 'rb');

        $paths = [];
        $pathIds = [];
        $idPaths = [];
        $dateIds = [];
        $nextId = 0;

        while (($line = fgets($file)) !== false) {
            $line = substr($line, self::PREFIX_LENGTH, self::TIME_LENGTH);
            $parts = explode(self::DELIMITER, $line, 2);
            $path = $parts[0];
            $date = $parts[1];

            if (!isset($pathIds[$path])) {
                $pathIds[$path] = $nextId;
                $idPaths[$nextId] = $path;
                $nextId++;
            }

            $pathId = $pathIds[$path];

            if (!isset($dateIds[$date])) {
                $y = substr($date, 3, 1);
                $m = substr($date, 5, 2);
                $d = substr($date, 8, 2);
                $dateId = (int)($y . $m . $d);

                $dateIds[$date] = $dateId;
            } else {
                $dateId = $dateIds[$date];
            }

            $dates =& $paths[$pathId];
            if (isset($dates[$dateId])) {
                $dates[$dateId]++;
            } else {
                $dates[$dateId] = 1;
            }
        }

        gc_enable();
        fclose($file);
        unset($file, $line, $dates, $nextId, $dateIds);

        $jsons = [];
        foreach ($paths as $pathId => &$dates) {
            if (count($dates) > 1) {
                ksort($dates);
            }
            $data = [];
            foreach ($dates as $dateId => &$count) {
                $date = (string)$dateId;
                $s = '202' . substr($date, 0, 1) . '-' . substr($date, 1, 2) . '-' . substr($date, 3, 2);
                $data[$s] = $count;
            }
            $jsons[$idPaths[$pathId]] = $data;
        }

        $json = json_encode($jsons, JSON_PRETTY_PRINT);
        unset($jsons, $idPaths, $paths, $dateId, $count);

        file_put_contents($outputPath, $json);
    }
}