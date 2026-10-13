<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\Support\IntegrationTestCase;

/**
 * PRD FR-16: every model except Tenant must be tenant-scoped, and every
 * table it maps to must carry tenant_id. New models can't opt out silently.
 */
final class TenantModelsTest extends IntegrationTestCase
{
    #[Test]
    public function every_model_except_tenant_uses_belongs_to_tenant(): void
    {
        $models = $this->modelClasses();
        self::assertNotEmpty($models);

        foreach ($models as $class) {
            $traits = class_uses_recursive($class);

            if ($class === Tenant::class) {
                self::assertArrayNotHasKey(BelongsToTenant::class, $traits);

                continue;
            }

            self::assertArrayHasKey(BelongsToTenant::class, $traits, sprintf('%s must use BelongsToTenant', $class));
            self::assertTrue(Schema::hasColumn((new $class)->getTable(), 'tenant_id'), sprintf('%s table lacks tenant_id', $class));
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];
        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Models\\'.$relative;
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }
            /** @var class-string<Model> $class */
            $classes[] = $class;
        }

        return $classes;
    }
}
