<?php

namespace App;

use Exception;

final class Parser
{
    const int PREFIX_LENGTH = 25;
    const int TIME_LENGTH = -16;
    const int FULL_DATE_LENGTH = 25;
    const string DELIMITER = ',';

    const int CHUNK_SIZE = 1024 * 1024;

    public function parse(string $inputPath, string $outputPath): void
    {
        ini_set('memory_limit', '512M');
        gc_disable();

        $fileSize = filesize($inputPath);
        $file = fopen($inputPath, 'rb');

        [$pathSet, $numPaths, $dateSet, $dateMap, $numDates] = $this->findAllDates($fileSize, $file);

        fseek($file, 0);

        $map = array_fill(0, $numPaths * $numDates, 0);

        $dateIds = [];
        $nextId = 0;

        $bytesProcessed = 0;
        while ($bytesProcessed < $fileSize) {
            $bytesRemaining = $fileSize - $bytesProcessed;
            $estimatedChunkSize = min(self::CHUNK_SIZE, $bytesRemaining);

            $data = fread($file, $estimatedChunkSize);
            if ($data === false) {
                break;
            }

            $chunkLengthBytes = strlen($data);
            $bytesProcessed += $chunkLengthBytes;

            $lastNewlineIndex = strrpos($data, "\n");
            if ($lastNewlineIndex !== false) {
                $overhangBytes = $chunkLengthBytes - $lastNewlineIndex - 1;
                fseek($file, -$overhangBytes, SEEK_CUR);
                $bytesProcessed -= $overhangBytes;
            }

            $processedIndex = 0;
            while ($processedIndex < $lastNewlineIndex) {
                $newlineIndex = strpos($data, "\n", $processedIndex);
                $line = substr(
                    $data,
                    $processedIndex + self::PREFIX_LENGTH,
                    $newlineIndex - $processedIndex - self::PREFIX_LENGTH + self::TIME_LENGTH + 1,
                );
                $parts = explode(self::DELIMITER, $line, 2);
                $path = $parts[0];
                $date = $parts[1];

                $pathIndex = $pathSet[$path];
                $dateIndex = $dateMap[$date];

                $map[$pathIndex * $numDates + $dateIndex]++;

                $processedIndex = $newlineIndex + 1;
            }
        }

        gc_enable();
        fclose($file);
        unset($file, $line, $dates, $nextId, $dateIds);

        $file = fopen($outputPath, 'w');
        stream_set_write_buffer($file, self::CHUNK_SIZE);
        $buffer = "{";

        $pathSeparator = '';
        foreach ($pathSet as $path => $pathId) {
            $buffer .= $pathSeparator;
            $pathSeparator = ',';

            $buffer .= "\n    \"\/blog\/$path\": {";

            $dateSeparator = '';
            for ($i = 0; $i < $numDates; $i++) {
                $count = $map[$pathId * $numDates + $i];
                if ($count === 0) {
                    continue;
                }
                $buffer .= $dateSeparator;
                $dateSeparator = ',';

                $date = $dateSet[$i];
                $buffer .= "\n        \"$date\": $count";
            }

            $buffer .= "\n    }";
        }

        fwrite($file, $buffer . "\n}");
        fclose($file);

        return;
    }

    protected function findAllDates(int $fileSize, $file): array
    {
        $numPaths = 0;
        $pathsSet = [];
        $minDate = "9999-99-99";
        $maxDate = "0000-00-00";

        $bytesProcessed = 0;
        while ($bytesProcessed < $fileSize) {
            $bytesRemaining = $fileSize - $bytesProcessed;
            $estimatedChunkSize = min(self::CHUNK_SIZE, $bytesRemaining);

            $data = fread($file, $estimatedChunkSize);
            if ($data === false) {
                break;
            }

            $chunkLengthBytes = strlen($data);
            $bytesProcessed += $chunkLengthBytes;

            $lastNewlineIndex = strrpos($data, "\n");
            if ($lastNewlineIndex !== false) {
                $overhangBytes = $chunkLengthBytes - $lastNewlineIndex - 1;
                fseek($file, -$overhangBytes, SEEK_CUR);
                $bytesProcessed -= $overhangBytes;
            }

            $processedIndex = 0;
            while ($processedIndex < $lastNewlineIndex) {
                $newlineIndex = strpos($data, "\n", $processedIndex);
                $dateStr = substr(
                    $data,
                    $newlineIndex - self::FULL_DATE_LENGTH,
                    self::FULL_DATE_LENGTH + self::TIME_LENGTH + 1,
                );

                if ($dateStr < $minDate) {
                    $minDate = $dateStr;
                }
                if ($dateStr > $maxDate) {
                    $maxDate = $dateStr;
                }

                $path = substr(
                    $data,
                    $processedIndex + self::PREFIX_LENGTH,
                    $newlineIndex - $processedIndex - self::PREFIX_LENGTH - self::FULL_DATE_LENGTH - 1,
                );

                if (! isset($pathsSet[$path])) {
                    $pathsSet[$path] = $numPaths++;
                }

                $processedIndex = $newlineIndex + 1;
            }
        }

        $minDateTs = strtotime($minDate);
        $numDates = (strtotime($maxDate) - $minDateTs) / 86400;

        echo "#paths: $numPaths\n";
        echo "#dates: $numDates\n";
        echo "min: $minDate\n";
        echo "max: $maxDate\n";
        $numDates = intdiv((strtotime($maxDate) - $minDateTs), 86400) + 1;

        $dateSet = [];
        $dateMap = [];
        for ($i = 0; $i < $numDates; $i++) {
            $dateStr = date('y-m-d', $i * 86400 + $minDateTs);
            $dateSet[$i] = $dateStr;
            $dateMap[$dateStr] = $i;
        }

        return [$pathsSet, $numPaths, $dateSet, $dateMap, $numDates];
    }
}