RM = rm -rf

all: doc

doc:
	cd Docs ; git clone https://github.com/depage/depage-docu.git depage-docu || true
	doxygen Docs/Doxyfile
	cp -r Docs/depage-docu/www/lib Docs/html/

test:
	vendor/bin/phpunit Tests/ --stop-on-failure --bootstrap Tests/bootstrap.php

testCurrent:
	vendor/bin/phpunit Tests/ --stop-on-failure --bootstrap Tests/bootstrap.php --filter testTaskGenerator --display-deprecations

clean:
	$(RM) Docs/depage-docu/ Docs/html/

.PHONY: all
.PHONY: clean
.PHONY: test
.PHONY: doc


