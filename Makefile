.PHONY: up down reset test logs shell migrate fresh

up:        ## build and start everything (also works without the Makefile: docker compose up)
	@test -f .env || cp .env.example .env
	docker compose up --build -d
down:
	docker compose down
reset:     ## wipe all volumes and start clean
	docker compose down -v && $(MAKE) up
test:
	docker compose --profile test run --rm --build tests
logs:
	docker compose logs -f --tail=100
shell:
	docker compose exec app sh
migrate:
	docker compose run --rm migrate
fresh:     ## drop all tables and reseed
	docker compose run --rm migrate sh -c "php artisan migrate:fresh --force --seed"
