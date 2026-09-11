SAFE_UPDATE = php $(HOME)/Working/dev-process/scripts/safe-update.php

.PHONY: help \
        test lint ecs ecs-fix \
        deps-audit deps-update \
        check on-commit on-push \
        clean

# ── Meta ─────────────────────────────────────────

help: ## Show available targets
	@grep -E '^[a-z][-a-z]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-28s\033[0m %s\n", $$1, $$2}'

# ── Checks ───────────────────────────────────────

test: ## Unit tests (nette/tester, tests/unit)
	@vendor/bin/tester -C tests/unit -s

lint: ## Static analysis (PHPStan level max)
	@vendor/bin/phpstan analyse --memory-limit=512M

ecs: ## Coding standard, check only
	@vendor/bin/ecs check

ecs-fix: ## Coding standard, apply
	@vendor/bin/ecs check --fix

# ── Dependencies ─────────────────────────────────

deps-audit: ## Security audit (composer)
	@composer audit

deps-update: ## Age-checked dependency report (safe-update.php)
	@$(SAFE_UPDATE) --dir . || [ $$? -eq 1 ]   # exit 1 = findings (ok); 2 = tool error (fail)

# ── Gates ────────────────────────────────────────

check: lint ecs test deps-audit ## Everything a change has to pass

on-commit: check ## Pre-commit gate

on-push: check ## Push gate (adds manifest validation)
	@composer validate --strict

# ── Housekeeping ─────────────────────────────────

clean: ## Remove test artefacts
	@rm -rf tests/unit/output
