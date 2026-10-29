CLI_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
$(eval $(sort $(subst :,\:,$(CLI_ARGS))):;@:)

ifeq ($(shell uname), Darwin)
    export UID=1000
    export GID=1000
else
    export UID=$(shell id -u)
    export GID=$(shell id -g)
endif

export COMPOSE_PROJECT_NAME=yiipress-catalog
RUN := docker compose -f docker/compose.yml run --rm generator

.PHONY: build composer test phpstan cs-fix check generate help

build: ## Build the generator image
	docker compose -f docker/compose.yml build

composer: ## Run Composer in generator/
	$(RUN) composer $(CLI_ARGS)

test: ## Run the generator tests
	$(RUN) vendor/bin/phpunit $(CLI_ARGS)

phpstan: ## Run PHPStan
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G

cs-fix: ## Run PHP CS Fixer
	$(RUN) vendor/bin/php-cs-fixer fix

check: ## Validate the catalog metadata (no network for unchanged entries)
	$(RUN) php bin/catalog check $(CLI_ARGS)

generate: ## Fetch release data and write the site content
	$(RUN) php bin/catalog generate $(CLI_ARGS)

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "%-12s %s\n", $$1, $$2}'
