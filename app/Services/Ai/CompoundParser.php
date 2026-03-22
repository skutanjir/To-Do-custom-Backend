<?php
// app/Services/Ai/CompoundParser.php

namespace App\Services\Ai;

class CompoundParser
{
    /** @var array Delimiters for splitting compound commands */
    private array $delimiters = [
        ' terus ', ' then ', ' and ', ' dan ', ' lalu ', ' then ', ' disusul ', ' and also ',
        ' abis itu ', ' kemudian ', ' after that '
    ];

    /**
     * Splits a compound message into atomic commands.
     */
    public function split(string $message): array
    {
        $commands = [$message];

        foreach ($this->delimiters as $delimiter) {
            $newCommands = [];
            foreach ($commands as $cmd) {
                $parts = explode($delimiter, $cmd);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!empty($part)) {
                        $newCommands[] = $part;
                    }
                }
            }
            $commands = $newCommands;
        }

        return $commands;
    }

    /**
     * Determines if a message is compound.
     */
    public function isCompound(string $message): bool
    {
        foreach ($this->delimiters as $delimiter) {
            if (str_contains($message, $delimiter)) {
                return true;
            }
        }
        return false;
    }
}
