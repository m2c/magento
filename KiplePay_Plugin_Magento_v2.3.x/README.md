This KiplePay payment module tested for Magento version 2.3.1. to version 2.3.4

How to Install --

Step 1: in root directory under app folder create new folder with name code and copy KiplePay folder inside code folder.

Step 2: php bin\magento setup:upgrade [composer command]

Step 3: php bin/magento setup:static-content:deploy -f [composer command]

Step 4: If show error memory-limit-errors for more info on how to handle out of memory errors. 
    php -dmemory_limit=-1 bin/magento setup:static-content:deploy -f [composer command]

Step 4: php bin/magento cache:flush [composer command]

Enable KiplePay Payment Integration Module--

Step 1: Login Magento Admin panel.

Step 2: STORES >> CONFIGURATION >> SALES >> Payment Methods show option KiplePay Payment Integration

Step 3: Enabled Select Yes. Title enter KiplePay Payment.

Step 4: If Testing Mode Yes then use Merchant ID 80000155 and Secret Key 123456.
       For Live trasactions use Testing Mode No and enter live Merchant ID and Secret Key.

Step 5: Then click on Save Config.