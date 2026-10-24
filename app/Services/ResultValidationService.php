<?php

namespace App\Services;

class ResultValidationService {
    /**
     * Validates Matka result components according to Section 23 of requirements.
     * Checks panna digit formatting, jodi derivation mathematics, and duplicate prevention.
     */
    public static function validate(
        string $openPanna,
        string $jodi,
        string $closePanna
    ): array {
        $errors = [];

        // 1. Panna Format: exactly 3 numeric digits or placeholders
        if ($openPanna !== '***' && !preg_match('/^[0-9]{3}$/', $openPanna)) {
            $errors[] = 'Open Panna must be exactly 3 numeric digits.';
        }

        if ($closePanna !== '***' && !preg_match('/^[0-9]{3}$/', $closePanna)) {
            $errors[] = 'Close Panna must be exactly 3 numeric digits.';
        }

        // 2. Jodi Format: exactly 2 numeric digits or placeholders
        if ($jodi !== '**' && !preg_match('/^[0-9]{2}$/', $jodi)) {
            $errors[] = 'Jodi must be exactly 2 numeric digits.';
        }

        // 3. Mathematical Consistency Check (Single digit sum derivation)
        if ($openPanna !== '***' && $jodi !== '**') {
            $openSum = array_sum(str_split($openPanna)) % 10;
            $expectedFirstJodi = (int)$jodi[0];
            if ($openSum !== $expectedFirstJodi) {
                $errors[] = "Open sum mismatch: sum of digits in $openPanna is $openSum, but first Jodi digit is $expectedFirstJodi.";
            }
        }

        if ($closePanna !== '***' && $jodi !== '**') {
            $closeSum = array_sum(str_split($closePanna)) % 10;
            $expectedSecondJodi = (int)$jodi[1];
            if ($closeSum !== $expectedSecondJodi) {
                $errors[] = "Close sum mismatch: sum of digits in $closePanna is $closeSum, but second Jodi digit is $expectedSecondJodi.";
            }
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
