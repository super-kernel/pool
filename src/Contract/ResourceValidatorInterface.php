<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

/**
 * Resource Verification Interface
 *
 * Used to determine whether a resource is still available.
 */
interface ResourceValidatorInterface
{
	/**
	 * Check if the resources are valid.
	 *
	 * @param object $resource
	 *
	 * @return bool
	 */
	public function validate(object $resource): bool;
}