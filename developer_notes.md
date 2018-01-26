# Developer notes


## Storing data in the log

As of 2016-03-01 Autonotify was modified to store the configuration in the log instead of using the DET query string.
This was done to alleviate issues around maximum query string length.  The new version offers the ability to upgrade existing
autonotify configurations on first use.


## How to send a DET request with curl

Assuming you are using this test VM access project 22 and reading data from record 7, a DET request could be generated like this:


    curl --data "project_id=22&redcap_url=http://redcap.test/redcap/&record=7" http://localhost/redcap/plugins/redcap-autonotify3/index.php

