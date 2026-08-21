# Symfony Database Testing Setup

## Requirements

- Symfony 6+
- Doctrine ORM
- PHPUnit

## Installation

```bash
composer require --dev dama/doctrine-test-bundle doctrine/doctrine-fixtures-bundle
```

## Configuration

### 1. Test database — `.env.test`

```env
DATABASE_URL="mysql://user:password@127.0.0.1:3306/myapp_test"
# or SQLite for simplicity:
# DATABASE_URL="sqlite:///%kernel.project_dir%/var/test.db"
```

### 2. Enable DAMA bundle — `config/bundles.php`

```php
DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
```

### 3. Enable savepoints — `config/packages/doctrine.yaml`

Required by DAMADoctrineTestBundle to wrap tests in transactions.

```yaml
doctrine:
    dbal:
        use_savepoints: true
        url: '%env(resolve:DATABASE_URL)%'
```

## Database Setup (first time)

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Re-run migrations whenever the schema changes:

```bash
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

## Fixtures

### Create a fixture

```php
// src/DataFixtures/VideoFixtures.php
class VideoFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $video = new Video();
        $video->setTitle('Test Video');
        $manager->persist($video);
        $manager->flush();
    }
}
```

### Load fixtures

```bash
XDEBUG_MODE=off php bin/console doctrine:fixtures:load --env=test --no-interaction
```

## Writing Tests

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class VideoTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testVideoExists(): void
    {
        $video = $this->em->getRepository(Video::class)->find(1);
        $this->assertNotNull($video);
    }
}
```

Use `KernelTestCase` for service/DB access, `WebTestCase` for HTTP requests.

## Running Tests

```bash
# Run all tests
XDEBUG_MODE=off bin/phpunit

# Run specific directory
XDEBUG_MODE=off bin/phpunit src/tests/Videos/

# With coverage
XDEBUG_MODE=coverage bin/phpunit src/tests/Videos/ --coverage-text
```

## How It Works

DAMADoctrineTestBundle wraps each test in a database transaction that is automatically rolled back after the test completes. This means:

- Tests are isolated from each other
- No manual cleanup needed
- Fixtures only need to be loaded once per test session