install: install-env \
	install-docker-override \
	install-php-xdebug-ini \
	install-docker-php-fpm \
	install-php-composer-packages \
	install-docker-mysql \
	start

install-env:
	cp -n ./docker/.env.example ./docker/.env
	cp -n ./src/.env.example ./src/.env

install-docker-override:
	cp -n ./docker/compose.override.yml.example ./docker/compose.override.yml

install-php-xdebug-ini:
	cp -n ./docker/php-fpm/assets/xdebug.ini.example ./docker/php-fpm/assets/xdebug.ini
	sed -i "s/XDEBUG_CLIENT_HOST/$(shell hostname -I | cut -d" " -f1)/" ./docker/php-fpm/assets/xdebug.ini

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

clean:
	cd docker && docker compose down -v
	git clean -fdx -e .idea

start:
	cd docker && docker compose up -d
	@echo "Open http://localhost:8080"

terminal:
	cd docker && docker compose exec -it php-fpm bash

db-seed:
	cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off artisan db:seed
