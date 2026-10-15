<?php

namespace App;

use App\Infrastructure\DependencyInjection\CQRS\RegisterDeserializableCommandsPass;
use App\Infrastructure\DependencyInjection\Mutex\AutowireWithMutexPass;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Tenancy\Tenant;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        getCacheDir as private getDefaultCacheDir;
    }

    public function __construct(
        string $environment,
        bool $debug,
        private readonly ?Tenant $tenant = null,
    ) {
        parent::__construct($environment, $debug);
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    #[\Override]
    public function getCacheDir(): string
    {
        if (!$this->tenant instanceof Tenant) {
            return $this->getDefaultCacheDir();
        }

        // Every user gets its own compiled container, sessions and caches.
        return $this->getProjectDir().'/var/cache/users/'.$this->tenant->getName().'/'.$this->environment;
    }

    #[\Override]
    protected function getContainerClass(): string
    {
        $containerClass = parent::getContainerClass();
        if (!$this->tenant instanceof Tenant) {
            return $containerClass;
        }

        // Kernels of multiple users live in the same PHP process, their containers need unique class names.
        return $containerClass.'_User_'.str_replace('-', '_', $this->tenant->getName());
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getKernelParameters(): array
    {
        return [
            ...parent::getKernelParameters(),
            // Root directory of everything the app writes: storage/, build/ and watch/.
            'app.data_dir' => $this->tenant?->getDataDirectory() ?? $this->getProjectDir(),
            'app.user' => $this->tenant?->getName() ?? '',
        ];
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(WithMutex::class, static function (ChildDefinition $definition, WithMutex $attribute): void {
            $definition->addTag('app.mutex', [
                'mutex' => sprintf('mutex.%s', $attribute->getLockName()->value),
            ]);
        });

        $container->addCompilerPass(new AutowireWithMutexPass());
        $container->addCompilerPass(new RegisterDeserializableCommandsPass());
    }
}
