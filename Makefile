install: install-env \
	install-docker-override \
	install-php-xdebug-ini \
	install-docker-php-fpm \
	install-php-composer-packages \
	install-docker-mysql \
	grumphp-init \
	start

install-env:
	cp -n ./docker/.env.example ./docker/.env
	cp -n ./src/.env.example ./src/.env

install-docker-override:
	cp -n ./docker/compose.override.yml.example ./docker/compose.override.yml

install-php-xdebug-ini:
	cp -n ./docker/php-fpm/assets/xdebug.ini.example ./docker/php-fpm/assets/xdebug.ini
	@if docker network inspect bridge >/dev/null 2>&1; then \
	   GATEWAY=$$(docker network inspect bridge | grep Gateway | cut -d'"' -f4); \
	   sed -i "s/XDEBUG_CLIENT_HOST/$$GATEWAY/" ./docker/php-fpm/assets/xdebug.ini; \
	else \
	   sed -i "s/XDEBUG_CLIENT_HOST/172.17.0.1/" ./docker/php-fpm/assets/xdebug.ini; \
	fi

install-docker-php-fpm:
	cd docker && \
	docker compose build --build-arg HOST_UID=$(shell id -u) php-fpm && \
	docker compose up -d php-fpm

install-php-composer-packages:
	cd docker && docker compose run --rm -T php-fpm composer install

install-docker-mysql:
	cd docker && \
	docker compose up -d postgres && \
	docker run --rm --network brainique_net jwilder/dockerize -wait tcp://postgres:5432 -timeout 30s

grumphp-init:
	@echo "🔧 Initializing GrumPHP Git hooks..."
	@if [ ! -f .git/hooks/pre-commit ]; then \
		echo "#!/bin/sh" > .git/hooks/pre-commit; \
		echo "cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off vendor/bin/grumphp run" >> .git/hooks/pre-commit; \
		chmod +x .git/hooks/pre-commit; \
		echo "✅ GrumPHP hook installed (calls Docker)"; \
	else \
		echo "⚠️  Hook already exists"; \
	fi

cs-check:
	@echo "🔍 Checking code style..."
	cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
	@echo "✅ Code style check completed!"

cs-fix:
	@echo "🔧 Fixing code style..."
	cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off vendor/bin/php-cs-fixer fix --allow-risky=yes
	@echo "✅ Code style fixed!"

stan:
	@echo "🔬 Running PHPStan analysis..."
	cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off vendor/bin/phpstan analyse --memory-limit=1G
	@echo "✅ PHPStan analysis completed!"

lint: cs-check stan
	@echo "✅ All linting checks passed!"

clean:
	cd docker && docker compose down -v
	git clean -fdx -e .idea

build:
	cd docker && docker compose build --no-cache

start:
	cd docker && docker compose up -d
	@echo "Open http://localhost:8080"

stop:
	cd docker && docker compose stop

restart: stop start
	@echo "🔄 Services restarted"

terminal:
	cd docker && docker compose exec -it php-fpm bash

db-seed:
	cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off artisan db:seed