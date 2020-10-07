<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\RateLimiter;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\NoLock;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * @author Wouter de Jong <wouter@wouterj.nl>
 *
 * @experimental in 5.2
 */
final class Limiter
{
    private $config;
    private $storage;
    private $lockFactory;

    public function __construct(array $config, StorageInterface $storage, ?LockFactory $lockFactory = null)
    {
        $this->storage = $storage;
        $this->lockFactory = $lockFactory;

        $options = new OptionsResolver();
        self::configureOptions($options);

        $this->config = $options->resolve($config);
    }

    public function create(?string $key = null): LimiterInterface
    {
        $id = $this->config['id'].$key;
        $lock = $this->lockFactory ? $this->lockFactory->createLock($id) : new NoLock();

        switch ($this->config['strategy']) {
            case 'token_bucket':
                return new TokenBucketLimiter($id, $this->config['limit'], $this->config['rate'], $this->storage, $lock);

            case 'fixed_window':
                return new FixedWindowLimiter($id, $this->config['limit'], $this->config['interval'], $this->storage, $lock);

            default:
                throw new \LogicException(sprintf('Limiter strategy "%s" does not exists, it must be either "token_bucket" or "fixed_window".', $this->config['strategy']));
        }
    }

    protected static function configureOptions(OptionsResolver $options): void
    {
        $intervalNormalizer = static function (Options $options, string $interval): \DateInterval {
            try {
                return (new \DateTimeImmutable())->diff(new \DateTimeImmutable('+'.$interval));
            } catch (\Exception $e) {
                if (!preg_match('/Failed to parse time string \(\+([^)]+)\)/', $e->getMessage(), $m)) {
                    throw $e;
                }

                throw new \LogicException(sprintf('Cannot parse interval "%s", please use a valid unit as described on https://www.php.net/datetime.formats.relative.', $m[1]));
            }
        };

        $options
            ->setRequired('id')
            ->setRequired('strategy')
            ->setAllowedValues('strategy', ['token_bucket', 'fixed_window'])
            ->addAllowedTypes('limit', 'int')
            ->addAllowedTypes('interval', 'string')
            ->setNormalizer('interval', $intervalNormalizer)
            ->setDefault('rate', function (OptionsResolver $rate) use ($intervalNormalizer) {
                $rate
                    ->define('amount')->allowedTypes('int')->default(1)
                    ->define('interval')->allowedTypes('string')->normalize($intervalNormalizer)
                ;
            })
            ->setNormalizer('rate', function (Options $options, $value) {
                if (!isset($value['interval'])) {
                    return null;
                }

                return new Rate($value['interval'], $value['amount']);
            })
        ;
    }
}
