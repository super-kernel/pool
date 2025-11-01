<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

/**
 * Resource Factory Interface
 *
 * Used to create new connections/clients, etc.
 */
interface ResourceFactoryInterface
{
	/**
	 * Create a new resource object.
	 *
	 * @return object
	 */
	public function create(): object;
}