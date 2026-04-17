PLUGIN_SLUG = gate-wp
VERSION     = $(shell grep "Version:" gate-wp.php | sed 's/.* //')
BUILD_DIR   = build
ARTIFACT    = $(BUILD_DIR)/$(PLUGIN_SLUG)-$(VERSION).zip

.PHONY: build clean analyse format

build: clean
	composer install --no-dev --optimize-autoloader --quiet
	mkdir -p $(BUILD_DIR)/$(PLUGIN_SLUG)
	rsync -a \
		--exclude='.git' \
		--exclude='.gitignore' \
		--exclude='Makefile' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='build' \
		--exclude='phpstan.neon' \
		--exclude='.php-cs-fixer.php' \
		--exclude='.php-cs-fixer.cache' \
		. $(BUILD_DIR)/$(PLUGIN_SLUG)/
	cd $(BUILD_DIR) && zip -rq $(PLUGIN_SLUG)-$(VERSION).zip $(PLUGIN_SLUG)
	rm -rf $(BUILD_DIR)/$(PLUGIN_SLUG)
	composer install --quiet
	@echo "Built $(ARTIFACT)"

analyse:
	vendor/bin/phpstan analyse --memory-limit=512M

format:
	vendor/bin/php-cs-fixer fix

clean:
	rm -rf $(BUILD_DIR)
