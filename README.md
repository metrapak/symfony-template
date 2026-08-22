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

## ⚙️ Account Module Configuration

Every one of these has a working default in `src/config/services.yaml`, so an existing deployment boots
with none of them set. Set the environment variable to override.

| Variable | Default | Effect |
|:---------|:--------|:-------|
| `SESSION_IDLE_TTL` | `604800` (7 days) | Idle window before an authenticated session is signed out. Also drives the session cookie lifetime and GC lifetime, so the three cannot disagree. |
| `EMAIL_VERIFICATION_REQUIRED` | `true` | Whether an unverified email blocks sign-in. Applies to players and coaches only — trainers and super admins are created by an administrator through a channel that already proves email control. |
| `IMPERSONATION_TTL` | `3600` (1 hour) | How long a Super Admin may stay switched into another account before the session is ended automatically and they are returned to their own view. |
| `MAILER_SENDER_ADDRESS` | `no-reply@example.com` | From address on transactional account mail. |
| `MAILER_SENDER_NAME` | `Brainique` | From name on transactional account mail. |

### Creating the first Super Admin

There is no UI path to a Super Admin account and no self-registration for any role, so the first one comes
from the CLI:

```bash
make terminal
bin/console app:account:create-super-admin admin@example.com --name="Ada Admin"
```

The password is prompted for (hidden) when omitted, which keeps it out of shell history and the process
list.

---

## 💳 Purchase Approvals (child spending)

### Scheduled expiry — required in every environment

A child's purchase request auto-denies 48 hours after it is made (FR-096). Nothing in the application
schedules that: it needs one cron entry, and without it pending requests never expire.

```cron
*/15 * * * *  cd /path/to/app && php bin/console app:approvals:expire
```

The interval is yours to choose — it is the bound on how late an expiry can be. The command is
idempotent, takes only requests that are actually due, and stays quiet when there is nothing to do, so
it is safe to run as often as you like and safe to run by hand:

```bash
make approvals-expire
```

### No payments are taken yet

Payment execution sits behind `App\Approval\Payment\PaymentProcessor` and the implementation that
ships is `FakePaymentProcessor`: it records the intent, writes a warning to the application log, and
succeeds. Approvals, denials, expiry, notifications and the audit trail are all real; **no money
moves**. Replacing it when the payments epic lands is one alias in `src/config/services.yaml`.

### Configuration

| Variable | Default | Effect |
|:---------|:--------|:-------|
| `APPROVAL_WINDOW_HOURS` | `48` | How long a purchase waits for a parent before it auto-denies. |
| `APP_BASE_URL` | `http://localhost:8080` | Absolute base for links in outgoing mail. Only used where there is no HTTP request to take a host from — which is exactly the expiry cron above, so set it wherever that runs. |

---

## 🐳 Docker Services

- **Nginx**: Web server (port 8080)
- **PHP-FPM**: PHP 8.2 execution environment
- **Postgres**: Database engine

---

## 📜 License

This project is proprietary.