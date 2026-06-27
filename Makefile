.DEFAULT_GOAL := help

.PHONY: help install test lint check fix hooks clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## Install PHP dev dependencies
	composer install

test: ## Run the PHPUnit suite
	composer test

lint: ## Static analysis (PHPStan)
	composer lint

check: ## Style check (PHP-CS-Fixer, dry-run)
	composer check

fix: ## Auto-fix style (PHP-CS-Fixer)
	composer fix

hooks: ## Enable the repo git hooks (.githooks)
	git config core.hooksPath .githooks
	@echo "git hooks enabled (.githooks)"

clean: ## Remove vendor and caches
	rm -rf vendor .php-cs-fixer.cache .phpunit.cache
