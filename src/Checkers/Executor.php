<?php

namespace Pbmedia\ApiHealth\Checkers;

use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Pbmedia\ApiHealth\Checkers\Checker;
use Pbmedia\ApiHealth\Checkers\CheckerHasFailed;
use Pbmedia\ApiHealth\Storage\CheckerState;

class Executor
{
    /**
     * The checker.
     *
     * @var \Pbmedia\ApiHealth\Checkers\Checker
     */
    private $checker;

    /**
     * The state of the checker.
     *
     * @var \Pbmedia\ApiHealth\Storage\CheckerState
     */
    private $state;

    /**
     * The caught exception if the checker fails.
     *
     * @var \Pbmedia\ApiHealth\Checkers\CheckerHasFailed
     */
    private $exception;

    /**
     * Boolean wether the checker has failed of not.
     *
     * @var bool
     */
    private $failed;

    /**
     * Creates an instance with the given checker
     *
     * @param \Pbmedia\ApiHealth\Checkers\Checker $checker
     */
    public function __construct(Checker $checker)
    {
        $this->checker = $checker;
        $this->state   = new CheckerState($checker);
    }

    /**
     * Shortcut for creating an instance for a checker class.
     *
     * @param  string $checkerClass
     * @return \Pbmedia\ApiHealth\Checkers\Executor
     */
    public static function make(string $checkerClass)
    {
        return new static($checkerClass::create());
    }

    /**
     * Returns a boolean wether the checker passes.
     *
     * @return bool
     */
    public function passes(): bool
    {
        if (is_null($this->failed)) {
            $this->handle();
        }

        return !$this->failed;
    }

    /**
     * Returns a boolean wether the checker fails.
     *
     * @return bool
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Returns the checker.
     *
     * @return \Pbmedia\ApiHealth\Checkers\Checker
     */
    public function getChecker(): Checker
    {
        return $this->checker;
    }

    /**
     * Returns the caught exception.
     *
     * @return \Pbmedia\ApiHealth\Checkers\CheckerHasFailed
     */
    public function getException(): CheckerHasFailed
    {
        return $this->exception;
    }

    /**
     * Runs the checker, stores the state and lets events take
     * care of sending the notifications.
     *
     * @return $this
     */
    public function handle()
    {
        $this->failed = false;

        try {
            $this->checker->run();
            $this->state->setToPassing();
        } catch (CheckerHasFailed $exception) {
            if ($this->state->retryIsAllowed()) {
                $this->handleAllowedRetry();
            } else {
                $this->exception = $exception;
                $this->failed    = true;
                $this->handleFailedChecker();
            }
        }

        return $this;
    }

    /**
     * Handler for whenever the checker fails. Stores the state or adds a timestamp
     * to the state if the checker previously failed.
     *
     * @return null
     */
    private function handleFailedChecker()
    {
        if ($this->state->exists() && $this->state->isFailing()) {
            return $this->state->addFailedTimestamp($this->exception);
        }

        $this->state->setToFailed($this->exception);
    }

    /**
     * Adds a retry timestamp to the state of checker or dispaches
     * the retry job.
     *
     * @return null
     */
    private function handleAllowedRetry()
    {
        if (!$this->state->exists()) {
            $this->state->setToPassing();
        }

        if (!$jobClass = $this->checker->retryCheckerJob()) {
            return $this->state->addRetryTimestamp();
        }

        $this->addCallbackBeforeRetryCheckerJob($jobClass);

        $job = new $jobClass(get_class($this->checker));

        if (method_exists($this->checker, 'withRetryJob')) {
            $this->checker->withRetryJob($job);
        }

        dispatch($job);
    }

    /**
     * Adds a callback before the retry job is executed which
     * will add a retry timestamp on the checker's state.
     *
     * @param string $job
     */
    private function addCallbackBeforeRetryCheckerJob(string $job)
    {
        Queue::before(function (JobProcessing $event) use ($job) {
            $resolvedName = $event->job->resolveName();

            $checkerClass = unserialize(
                $event->job->payload()['data']['command']
            )->checkerClass;

            if ($resolvedName === $job && $checkerClass === get_class($this->checker)) {
                $this->state->addRetryTimestamp();
            }
        });
    }
}
