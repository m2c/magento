Under the main folder app/

etc/modules/Mage_Webcash.xml
code/local/Mage/Webcash/
code/core/Mage/Checkout/Block/Callback.php

Upload or copy those file and folder into Magento root directory (installed folder) and its subdirectories
This won't replace any of your Magento system core file

<MagentoRoot>/app/etc/modules/Mage_Webcash.xml
<MagentoRoot>/app/code/local/Mage/Webcash/
<MagentoRoot>/app/code/core/Mage/Checkout/Block/Callback.php

(Skip this if your magento is not hosted not in UNIX environment) Please ensure the file permission is correct. 

For starting installation purpose, It's recommended to CHMOD to 777 or any permission that allow the file to be read and write
<MagentoRoot>/app/etc/modules/Mage_Webcash.xml


Login as Magento Store Admin, go to System > Cache Management > Flush Cache Storage

Then proceed to System > Configuration > ADVANCED > Advanced
Under panel Disabled Module Output, Please ensure that Mage_Webcash module is in Enabled state

Save the advance Disabled Module Output

System > Configuration > SALES menu (at the sidebar), click on the Payment Methods.

Under panel Webcash payment method. Again, please ensure it's on enable state. 

Change New Order Status to Pending.

Test Mode:
Enable the test mode and insert merchant id as 80000155. You can proceed to make test payment for your products.

Live Mode:
Insert your Webcash Merchant ID which you should have received in separate email. Then save the config

