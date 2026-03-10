# Brainique Backend

A robust Symfony 7.4 application template following **Domain-Driven Design (DDD)** principles.

## 🚀 Features

- **Symfony 7.4** with PHP 8.2+.
- **Domain-Driven Design (DDD)** architecture.
- **PostgreSQL** database integrated via Doctrine ORM.
- **Dockerized** environment (Nginx, PHP-FPM, Postgres).
- **Symfony Messenger** for asynchronous task handling.
- **Tailwind CSS** integration for styling.
- **Code Quality Tools**: PHPStan (level 8/max), PHP-CS-Fixer, GrumPHP.
- **Testing**: Pre-configured PHPUnit and DAMA Doctrine Test Bundle.

## 🛠️ Tech Stack

- **Framework**: [Symfony](https://symfony.com/)
- **ORM**: [Doctrine](https://www.doctrine-project.org/)
- **Database**: [PostgreSQL](https://www.postgresql.org/)
- **Runtime**: [PHP 8.2 / 8.3](https://www.php.net/)
- **Infrastructure**: [Docker](https://www.docker.com/) & [Docker Compose](https://docs.docker.com/compose/)
- **Styling**: [Tailwind CSS](https://tailwindcss.com/)

---

## 🏁 Getting Started

### Prerequisites

- [Docker](https://www.docker.com/get-started) and [Docker Compose](https://docs.docker.com/compose/install/)
- [Make](https://www.gnu.org/software/make/)

### Installation

1.  **Clone the repository**:
    ```bash
    git clone <repository-url>
    cd brainique-backend
    ```

2.  **Run the installation command**:
    ```bash
    make install
    ```
    *This command will:*
    - Copy `.env` examples to `.env`.
    - Build and start Docker containers.
    - Install PHP dependencies via Composer.
    - Initialize GrumPHP Git hooks.

3.  **Access the application**:
    The application will be available at [http://localhost:8080](http://localhost:8080).

---

## 🏗️ Project Structure

The project follows a DDD approach located in the `src/src` directory:

- **src/src/Shared**: Shared logic, base components, and infrastructure.
- **src/src/Products**: Product domain (Entities, Commands, Repositories).
- **src/src/Starships**: Starships domain logic.
- **src/src/ToDoList**: Simple ToDo list domain.
- **src/src/Videos**: Video domain logic.

Each domain is typically structured into:
- `Domain`: Entities, Value Objects, Repository Interfaces, Domain Events.
- `Application`: Command/Query Handlers, Use Cases.
- `Infrastructure`: Controllers, Persistence implementations (Doctrine Repositories), Serializers.

---

## 🛡️ Code Quality & Testing

### Quality Tools

- **Check Code Style**: `make cs-check`
- **Fix Code Style**: `make cs-fix`
- **Static Analysis**: `make stan` (runs PHPStan)
- **Full Lint**: `make lint` (runs checkstyle and stan)

### Testing

Run the automated test suite:
```bash
make test
```

### Database Seeding

Load database fixtures:
```bash
make db-seed
```

---

## 🐳 Docker Services

- **Nginx**: Web server (port 8080)
- **PHP-FPM**: PHP 8.2 execution environment
- **Postgres**: Database engine

---

## 📜 License

This project is proprietary.