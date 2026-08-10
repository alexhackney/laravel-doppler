<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\Validation\Problem;

/**
 * One or more validation rules refused the write.
 *
 * Never soft-failable. Doppler answered; the answer was wrong. That is a defect somebody
 * introduced, and hiding it is how it reaches production.
 */
final class ValidationFailed extends DopplerException
{
    /**
     * @param  list<Problem>  $problems
     */
    public function __construct(
        string $message,
        public readonly array $problems = [],
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::ValidationFailed;
    }

    /**
     * @param  list<Problem>  $problems
     */
    public static function fromProblems(array $problems): self
    {
        $count = count($problems);

        $message = sprintf(
            '%d validation problem%s refused the write. Nothing was written.',
            $count,
            $count === 1 ? '' : 's',
        );

        return new self($message, $problems);
    }
}
