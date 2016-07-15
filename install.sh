#!/bin/bash

ADMINEMAIL="phpciscospark@cisco.com"
function error {
	echo "ERROR: phpCiscoSpark install failed. Contact $ADMINEMAIL with all ERROR messages."
	exit 1
}

function finished {
	echo "phpCiscoSpark install complete. Review README.md to get started writing your Spark App."
	touch ./install.lock
	exit 0
}

SUDOWARN=0
function sudowarn {
	if [ $ROOT == 0 ]; then 
		if [ $SUDOWARN == 0 ]; then
			(>&2 echo "WARN: You may be prompted to provide your password to install required software.")
		fi
	fi
	SUDOWARN=1
}

cd $(dirname "$0")

UNAME=`uname -s`
case "$UNAME" in

	Darwin)
		OS='osx'
		;;

	Linux)
		OS='linux'
		if [ -z `which which 2> /dev/null || echo ""` ]; then
			echo "ERROR: Missing which. Either unsupported OS ($UNAME) or you need to install which."
			error
		fi
		if [ -z `which apt-get` ]; then
			echo "ERROR: Missing apt-get. Either unsupported OS ($UNAME) or you need to install apt-get."
			error
		fi
		if [ -z `which whoami` ]; then
			echo "ERROR: Missing whoami. Either unsupported OS ($UNAME) or you need to install whoami."
			error
		fi
		;;

	#CYGWIN*|MINGW32*|MSYS*)
		#OS='msunix'
		#;;

	*)
		echo "ERROR: Unsupported OS: $UNAME" 
		error
		;;

esac

if [ `whoami` == 'root' ]; then
	ROOT=1
else
	ROOT=0
fi

if [ -z `which sudo` ]; then

	case "$OS" in

		linux)
			echo "ERROR: sudo is missing. You need to install sudo to proceed."
			error
			;;

		osx)
			echo "ERROR: sudo is missing. OS X should have sudo by default."
			error
			;;

	esac

fi

if [ -z `which php` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install php5
			;;

		osx)
			echo "ERROR: php is missing. OS X should have php by default."
			error
			;;

	esac

	if [ -z `which php` ]; then
		echo "ERROR: php install failed"
		error
	fi

fi

if [ -z `which phpize` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install php5-dev
			;;

		osx)
			echo "ERROR: phpize is missing. OS X should have phpize by default."
			error
			;;

	esac

	if [ -z `which phpize` ]; then
		echo "ERROR: php install failed. Missing phpize"
		error
	fi

fi

if [ -z `which make` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install build-essential
			;;

		osx)
			echo "WARNING: You will need to run this script again after completing installation of OS X Command Line Tools."
			xcode-select --install
			exit 0
			;;

	esac

	if [ -z `which make` ]; then
		echo "ERROR: make install failed"
		error
	fi

fi

if [ -z `which curl` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install curl
			;;

		osx)
			echo "ERROR: curl is missing. OS X should have curl by default."
			error
			;;

	esac

	if [ -z `which curl` ]; then
		echo "ERROR: curl install failed"
		error
	fi

fi

if [ -z `which tar` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install tar
			;;

		osx)
			echo "ERROR: tar is missing. OS X should have tar by default."
			error
			;;

	esac

	if [ -z `which tar` ]; then
		echo "ERROR: tar install failed"
		error
	fi

fi

if [ -z `which sed` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install sed
			;;

		osx)
			echo "ERROR: sed is missing. OS X should have sed by default."
			error
			;;

	esac

	if [ -z `which sed` ]; then
		echo "ERROR: sed install failed"
		error
	fi

fi

if [ -z `which autoconf` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install autoconf
			;;

		osx)
			cd tmp
			curl -OL http://ftpmirror.gnu.org/autoconf/autoconf-latest.tar.gz
			tar xzf autoconf-latest.tar.gz
			cd autoconf-*
			./configure --prefix=/usr/local
			make
			sudowarn
			sudo make install
			cd ..
			rm -r autoconf-*
			cd ..

	esac

	if [ -z `which autoconf` ]; then
		echo "ERROR: autoconf install failed"
		error
	fi

fi

PHPINI=`php -r 'echo php_ini_loaded_file();'`
if [ -z $PHPINI ]; then
	echo "ERROR: couldn't determine php configuration file"
	error
fi

if [ -z `php -r "echo extension_loaded('openssl');"` ]; then

	case "$OS" in

		linux)
			echo "ERROR: php openssl extension is missing. Debian-based Linux distros should have it by default if php was installed using apt-get."
			error
			;;

		osx)
			echo "ERROR: php openssl extension is missing. OS X should have it by default."
			error
			;;

	esac

fi

if [ -z `php -r "echo extension_loaded('curl');"` ]; then

	case "$OS" in

		linux)
			sudowarn
			sudo apt-get install php5-curl
			;;

		osx)
			echo "ERROR: php curl extension is missing. OS X should have it by default."
			;;

	esac

	if [ -z `php -r "echo extension_loaded('curl');"` ]; then
		echo "ERROR: failed to install curl extension"
		error
	fi

fi

if [ ! -z `php -r "echo extension_loaded('websockets');"` ]; then
	finished
fi

NEWPHPEXTDIR='/usr/local/lib/php/extensions'
NEWWEBSOCKETSSO="$NEWPHPEXTDIR/websockets.so"
WEBSOCKETSSO='tmp/phpwebsockets-1.4.1/ext/websockets/modules/websockets.so'
if [ ! -f $NEWWEBSOCKETSSO ]; then

	if [ ! -d $NEWPHPEXTDIR ]; then

		sudowarn
		sudo mkdir -p $NEWPHPEXTDIR

		if [ ! -d $NEWPHPEXTDIR ]; then
			echo "ERROR: failed to create new extensions directory"
			error
		fi

	fi

	case "$OS" in

		linux|osx)
			cd tmp
			tar xzf phpwebsockets-1.4.1.tar.gz
			cd phpwebsockets-1.4.1/ext/websockets
			phpize
			./configure
			make
			sudowarn
			sudo cp modules/websockets.so $NEWPHPEXTDIR
			cd ../../..
			rm -r phpwebsockets-1.4.1
			cd ..
			;;

	esac

	if [ ! -f $NEWWEBSOCKETSSO ]; then
		echo "ERROR: failed to copy websockets.so into new extensions directory"
		error
	fi

fi

if [ -z `grep "extension=$NEWWEBSOCKETSSO" $PHPINI` ]; then

	sudowarn
	sudo sed -i.phpCiscoSpark.backup -e $'$a\\\nextension=/usr/local/lib/php/extensions/websockets.so' $PHPINI

	if [ -z `grep "extension=$NEWWEBSOCKETSSO" $PHPINI` ]; then
		echo "ERROR: failed to update php configuration"
	fi

fi

if [ -z `php -r "echo extension_loaded('websockets');"` ]; then
	echo "ERROR: failed to install websockets extension"
	error
fi

finished
