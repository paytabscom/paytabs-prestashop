# Paytabs Prestashop

The official PrestaShop 1.7 plugin for PayTabs

- - -

## Installation

### Install using PrestaShop Admin panel

1. Download the compressed version from Releases page ([2.4.2](https://github.com/paytabscom/paytabs-prestashop1.7/releases/download/2.4.2/paytabs_paypage.zip))
2. Rename the downloaded ".zip" file to **paytabs_paypage.zip**
3. Go to `Prestashop admin panel >> Improve >> Modules >> Module Manager`
4. Click on `Upload a module` then select the `paytabs_paypage.zip` file
5. Wait until the installing completes

### Install using FTP method

1. Download the compressed version from Releases page ([2.4.2](https://github.com/paytabscom/paytabs-prestashop1.7/releases/download/2.4.2/paytabs_paypage.zip))
2. Upload the the folder `paytabs_paypage` to Prestashop site directory: `root/modules/`
3. Go to `Prestashop admin panel >> Improve >> Modules >> Module Catalog`
4. Search for `PayTabs`
5. Click on `Install`

- - -

## Activating/DeActivating the Plugin

1. Go to `Prestashop admin panel >> Improve >> Modules >> Module Manager`
2. Look for `PayTabs - PayPage` module
3. Click the `Enable` button in case the module disabled
4. From the drop down menu, Select `Disable` to disable the module

- - -

## Configure the Plugin

1. Go to `Prestashop admin panel >> Improve >> Modules >> Module Manager`
2. Look for `PayTabs - PayPage` module, and click `configure`
3. Navigate to the preferred payment method from the available list of PayTabs payment methods
4. Check the `Enabled` switch
5. Enter the primary credentials:
   - **Merchant e-Mail**: enter the email id of your PayTabs merchant account
   - **Secret Key**: `Merchant’s Dashboard >> Secret Key`
6. Configure other options as your need
7. Click `Save` button *(located on bottom-right corner)*

- - -

## Access the Log

1. Go to `Prestashop admin panel >> Configure >> Advanced Parameters >> Logs`
2. Search for **Message**s containing `PayTabs` as this is the prefix for all PayTabs' plugin's messages

- - -

Done
