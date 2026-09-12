.PHONY: help \
        test lint ecs ecs-fix docs docs-check dist-check \
        deps-audit \
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

docs: ## Refresh generated documentation blocks
	@php tools/update-docs.php

docs-check: ## Refuse documentation that drifted from its tested source
	@php tools/update-docs.php --check

dist-check: ## Allow only runtime code and documentation into the release archive
	@php tools/check-distribution.php

# ── Dependencies ─────────────────────────────────

# No age-checked update target here: that checker lives outside this repo, and naming its path in a
# versioned file makes the Makefile right on one machine and wrong in every clone. Two pinned
# runtime requirements and three pinned dev ones are a tree to run the workspace tool AT, from
# wherever it is installed — not a dependency this package takes on.

deps-audit: ## Security audit (composer)
	@composer audit

# ── Gates ────────────────────────────────────────

check: lint ecs docs-check test deps-audit ## Everything a change has to pass

on-commit: check ## Pre-commit gate

on-push: check dist-check ## Push gate (adds manifest and release-archive validation)
	@composer validate --strict

# ── Housekeeping ─────────────────────────────────

clean: ## Remove test artefacts
	@rm -rf tests/unit/output
