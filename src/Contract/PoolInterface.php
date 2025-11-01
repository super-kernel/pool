<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

interface PoolInterface
{
	public function __construct(
		ResourceFactoryInterface   $factory,
		ResourceValidatorInterface $validator,
		?PoolConfigInterface       $poolConfig = null,
	);

	public function get(): object;

	public function release(object $resource): void;

	public function close(): void;
}