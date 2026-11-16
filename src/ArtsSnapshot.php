<?php

class ArtsSnapshot
{
    public $lines;
    public $lineEnding;
    public $hasFinalLineEnding;
    public $hash;

    public function __construct(array $lines, $lineEnding, $hasFinalLineEnding, $hash)
    {
        $this->lines = $lines;
        $this->lineEnding = $lineEnding;
        $this->hasFinalLineEnding = $hasFinalLineEnding;
        $this->hash = $hash;
    }

    public function contentsWithLines(array $lines)
    {
        $contents = implode($this->lineEnding, $lines);

        if ($this->hasFinalLineEnding && count($lines) > 0) {
            $contents .= $this->lineEnding;
        }

        return $contents;
    }
}
