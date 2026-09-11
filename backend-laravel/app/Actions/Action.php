<?php

namespace App\Actions;

/**
 * Base contract for application actions.
 *
 * An Action represents a single, meaningful use case. It owns the
 * transaction boundary when an operation modifies multiple pieces of
 * domain state.
 *
 * Controllers should delegate to Actions. Actions should delegate to
 * domain engines/rules and repositories. Controllers must not contain
 * business logic.
 */
interface Action {}
