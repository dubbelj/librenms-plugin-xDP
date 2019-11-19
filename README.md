# librenms-plugin-xDP
A LibreNMS plugin that show what cdp/lldp neibours that is connected to a device and can export to Weathermap configuration

# Requirements
This plugin is dependent of GraphWiz https://www.graphviz.org/ tool "dot" to generate the pictures.
dot need to be in the $PATH.

# INSTALL
Copy the xDP directory to your librenms/html/plugins/ directory. In Librenms go to Overview->Plugins->Plugin Admin Click enable on "xDP"

# USAGE
Go to Overview->Plugins->xDP
1. Select one of your devices
2. Press one of the buttons to generate list of neibours, graph or Weathermap data.
3. Optional is to for example add additional port_ids to include in the graph or enter regexps to exclude neibour name/type. For example I like to exclude all IP-phone and they all start with SEP enter ^SEP in "Exclude unmanaged neibours where device name match" 
