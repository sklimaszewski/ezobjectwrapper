#!/usr/bin/env bash

# Set up configuration files

# Uses env vars: EZ_VERSION, KERNEL_DIR, INSTALL_TAGSBUNDLE

# @todo check if all required vars have a value

if [ "${EZ_VERSION}" = "ezplatform3" ]; then
    APP_DIR=vendor/ezsystems/ezplatform
    CONFIG_DIR=${APP_DIR}/config
    EZ_KERNEL=Kernel
elif [ "${EZ_VERSION}" = "ezplatform2" ]; then
    APP_DIR=vendor/ezsystems/ezplatform
    CONFIG_DIR=${APP_DIR}/app/config
    EZ_KERNEL=AppKernel
elif [ "${EZ_VERSION}" = "ezplatform" ]; then
    APP_DIR=vendor/ezsystems/ezplatform
    CONFIG_DIR=${APP_DIR}/app/config
    EZ_KERNEL=AppKernel
elif [ "${EZ_VERSION}" = "ezpublish-community" ]; then
    APP_DIR=vendor/ezsystems/ezpublish-community
    CONFIG_DIR=${APP_DIR}/ezpublish/config
    EZ_KERNEL=EzPublishKernel
else
    echo "Unsupported eZ version: ${EZ_VERSION}"
    exit 1
fi

# hopefully these bundles will stay there :-) it is important that they are loaded after the kernel ones...
if [ "${EZ_VERSION}" = "ezplatform3" ]; then
    LAST_BUNDLE=Overblog\GraphiQLBundle\OverblogGraphiQLBundle
elif [ "${EZ_VERSION}" = "ezplatform" -o "${EZ_VERSION}" = "ezplatform2" ]; then
    LAST_BUNDLE=AppBundle
else
    LAST_BUNDLE=OneupFlysystemBundle
fi

# eZ5/eZPlatform config files
if [ -f ${CONFIG_DIR}/parameters.yml.dist ]; then
    cp ${CONFIG_DIR}/parameters.yml.dist ${CONFIG_DIR}/parameters.yml
fi
if [ -f Tests/config/${EZ_VERSION}/config_behat.yml ]; then
    mv ${CONFIG_DIR}/config_behat.yml ${CONFIG_DIR}/config_behat_orig.yml
    cp Tests/config/${EZ_VERSION}/config_behat.yml ${CONFIG_DIR}/config_behat.yml
fi
cp Tests/config/common/config_behat.php ${CONFIG_DIR}/config_behat.php
if [ -f Tests/config/${EZ_VERSION}/ezpublish_behat.yml ]; then
    mv ${CONFIG_DIR}/ezpublish_behat.yml ${CONFIG_DIR}/ezpublish_behat_orig.yml
    cp Tests/config/${EZ_VERSION}/ezpublish_behat.yml ${CONFIG_DIR}/ezpublish_behat.yml
fi
if [ -f Tests/config/${EZ_VERSION}/ezplatform.yml ]; then
    mv ${CONFIG_DIR}/packages/behat/ezplatform.yaml ${CONFIG_DIR}/packages/behat/ezplatform_orig.yaml
    cp Tests/config/${EZ_VERSION}/ezplatform.yml ${CONFIG_DIR}/packages/behat/ezplatform.yaml
fi


# Load the wrapper bundle in the Sf kernel
fgrep -q 'new Kaliop\eZObjectWrapperBundle\KaliopeZObjectWrapperBundle()' ${KERNEL_DIR}/${EZ_KERNEL}.php
if [ $? -ne 0 ]; then
    sed -i 's/$bundles = array(/$bundles = array(new Kaliop\\eZObjectWrapperBundle\\KaliopeZObjectWrapperBundle(),/' ${KERNEL_DIR}/${EZ_KERNEL}.php
    sed -i 's/$bundles = \[/$bundles = \[new Kaliop\\eZObjectWrapperBundle\\KaliopeZObjectWrapperBundle(),/' ${KERNEL_DIR}/${EZ_KERNEL}.php
    # And the Migration bundle
    # we load it after the Kernel bundles... hopefully NelmioCorsBundle will stay there :-)
    sed -i "s/${LAST_BUNDLE}()/i new Kaliop\\\\eZMigrationBundle\\\\EzMigrationBundle(),/" ${KERNEL_DIR}/${EZ_KERNEL}.php
fi

# And optionally the Netgen tags bundle

# For eZPlatform, load the xmltext bundle
if [ "${EZ_VERSION}" = "ezplatform" -o "${EZ_VERSION}" = "ezplatform2" ]; then
    fgrep -q 'new EzSystems\EzPlatformXmlTextFieldTypeBundle\EzSystemsEzPlatformXmlTextFieldTypeBundle()' ${KERNEL_DIR}/${EZ_KERNEL}.php
    if [ $? -ne 0 ]; then
        sed -i "/${LAST_BUNDLE}()/i new EzSystems\\\\\EzPlatformXmlTextFieldTypeBundle\\\\\EzSystemsEzPlatformXmlTextFieldTypeBundle()," ${KERNEL_DIR}/${EZ_KERNEL}.php
    fi
fi

# Fix the eZ5/eZPlatform autoload configuration for the unexpected directory layout
if [ -f "${KERNEL_DIR}/autoload.php" ]; then
    sed -i "s#'/../vendor/autoload.php'#'/../../../../vendor/autoload.php'#" ${KERNEL_DIR}/autoload.php
fi

# and the one for eZPlatform 3

# as well as the config for jms_translation

# Fix the eZ console autoload config if needed (ezplatform 2 and ezplatform 3)

# Set up legacy settings and generate legacy autoloads
if [ "${EZ_VERSION}" = "ezpublish-community" ]; then
    cat WrapperBundle/Tests/config/ezpublish-legacy/config.php > vendor/ezsystems/ezpublish-legacy/config.php
    cd vendor/ezsystems/ezpublish-legacy && php bin/php/ezpgenerateautoloads.php && cd ../../..
fi

# Fix the phpunit configuration if needed
if [ "${EZ_VERSION}" = "ezplatform" -o "${EZ_VERSION}" = "ezplatform2" ]; then
    sed -i 's/"vendor\/ezsystems\/ezpublish-community\/ezpublish"/"vendor\/ezsystems\/ezplatform\/app"/' phpunit.xml.dist
elif [ "${EZ_VERSION}" = "ezplatform3" ]; then
    sed -i 's/"vendor\/ezsystems\/ezpublish-community\/ezpublish"/"vendor\/ezsystems\/ezplatform\/src"/' phpunit.xml.dist
fi