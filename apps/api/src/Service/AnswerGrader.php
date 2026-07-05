<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Question;

/**
 * Auto-grades a student's response against a question's validated correct
 * answer. Because publishing enforces the answer gate, every gradable question
 * is guaranteed to have a well-formed answer to grade against.
 */
final class AnswerGrader
{
    /** @return array{correct: bool, marks: int} */
    public function grade(Question $question, mixed $response): array
    {
        $correct = $this->isCorrect($question, $response);
        return ['correct' => $correct, 'marks' => $correct ? $question->getMarks() : 0];
    }

    private function isCorrect(Question $question, mixed $response): bool
    {
        $answer = $question->getCorrectAnswer();
        switch ($question->getType()) {
            case 'mcq':
                return is_string($response) && $response !== '' && $response === $answer;
            case 'true_false':
                return $this->boolStr($response) === $this->boolStr($answer);
            case 'short':
                $accepted = is_array($answer) ? $answer : ($answer !== null && $answer !== '' ? [$answer] : []);
                $normalized = $this->normalize($response);
                if ($normalized === '') {
                    return false;
                }
                foreach ($accepted as $candidate) {
                    if ($this->normalize($candidate) === $normalized) {
                        return true;
                    }
                }
                return false;
            case 'numeric':
                $value = is_array($answer) ? ($answer['value'] ?? null) : $answer;
                $tolerance = is_array($answer) ? (float) ($answer['tolerance'] ?? 0) : 0.0;
                if (!is_numeric($response) || !is_numeric($value)) {
                    return false;
                }
                return abs((float) $response - (float) $value) <= $tolerance;
            default:
                return false;
        }
    }

    private function boolStr(mixed $value): string
    {
        return ($value === true || $value === 'true' || $value === 1 || $value === '1') ? 'true' : 'false';
    }

    private function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
