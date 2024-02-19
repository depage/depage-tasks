<?php
/**
 * @file    MethodResult.php
 *
 * description
 *
 * copyright (c) 2024 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\Tasks;

/**
 * @brief MethodResult
 * Class MethodResult
 */
class MethodResult
{
    public function __construct(
        public readonly string $methodName,
        public readonly mixed $result,
    ) {
    }
}

// vim:set ft=php sw=4 sts=4 fdm=marker et :

