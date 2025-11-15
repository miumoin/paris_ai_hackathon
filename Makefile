.PHONY: build-TWKnowledgeManager

build-TWKnowledgeManager:
	sed -i '' 's/APP_ENV=.*/APP_ENV=prod/' .env
	npm install
	npm run build
	zip -r ask.typewriting.ai.zip src config public templates translations bin composer.json composer.lock symfony.lock .env
	scp ask.typewriting.ai.zip ubuntu@62.210.163.124:/var/www/html/typewriting/html/ask.typewriting.ai 
	ssh ubuntu@62.210.163.124 "cd /var/www/html/typewriting/html/ask.typewriting.ai && unzip -o ask.typewriting.ai.zip && rm ask.typewriting.ai.zip && composer install --no-dev --optimize-autoloader && php bin/console cache:clear --env=prod && php bin/console cache:warmup --env=prod"
	unlink ask.typewriting.ai.zip
	sed -i '' 's/APP_ENV=.*/APP_ENV=dev/' .env
	php bin/console cache:clear --env=dev
	php bin/console cache:warmup --env=dev
	npm run dev