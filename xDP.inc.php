<?php

global $plugin_name;
$plugin_name="xDP";

#echo "Well done, the $plugin_name plugin system is up and running";
#echo "<br>This is under development, expect some fun bugs :p<br>";

global $max_iterations;
$max_iterations=5;
global $device_id2group;
$device_id2group=array();
global $devices_in_group;
$devices_in_group=array();
global $devices;
$devices=array();
global $name_printed;
$name_printed=array();

#foreach($_COOKIE as $field => $value){
#	print "$field=$value<br>";
#}
#foreach($_POST as $field => $value){
#	print "$field=$value<br>";
#}

print_form();

if ($_POST["start_id"] && data_ok($_POST["start_id"],"number")){
	#$current_settings=get_settings();
	if ($_POST["include_port_id"] && data_ok($_POST["include_port_id"],"custom","/^[0-9,]*$/")){
		$include_port_ids=explode(",", $_POST["include_port_id"]);
	}else{
		$include_port_ids=array(); # Set it to a empty array.
	}
	$xDP=array();
	$xDP=do_xDP_check($_POST["start_id"], $xDP, 0);
	$xDP=add_included_ports($xDP,$include_port_ids);
	$xDP=get_interface_data($xDP);
	if ($_POST["display"] == "print_connections"){
		print print_connections($xDP);
	}elseif ($_POST["display"] == "print_graphdata"){
		#print "<pre>".print_graphdata($xDP)."</pre>";
		print get_graphwiz_data($xDP);
		#print "<br>DEBUG:<pre>".print_graphdata($xDP)."</pre>";
	}elseif ($_POST["display"] == "print_weathermap_data"){
		if ($_POST["name4weather"] && data_ok($_POST["name4weather"], "string")){
			print "Save output to ~librenms/html/plugins/Weathermap/configs/".$_POST["name4weather"].".conf";
		}
		print "<pre>".print_weathermap_data($xDP)."</pre>";
	}
}else{
	if ($_POST["start_id"]){
		echo "HAHA, very funny...<br>\n";
	}
}

function add_included_ports($xDP, $included_port_ids){
	foreach($included_port_ids as $array_id => $port_id){
		$portsql.="port_id='$port_id' OR ";
	}
	$portsql=substr($portsql, 0, -4);
	$query="SELECT port_id,device_id,ifAlias,ifName FROM ports WHERE $portsql;";
	foreach( dbFetchRows($query) as $line){
		$port_id=$line["port_id"];
		$device_id=$line["device_id"];
		$ifAlias=$line["ifAlias"];
		$ifName=$line["ifName"];
		$xDP[$device_id][$port_id]="$ifAlias:custom_add_port:n/a";
		$xDP["interface_data"][$port_id]["neibour"]="$ifAlias:n/a";
	}
	return $xDP;
}

function get_graphwiz_data($xDP, $outputformat="svg"){
	$descriptorspec = array(
		0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
		1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
		2 => array("pipe", "w") // stderr is a file to write to
	);
	$cwd = "/tmp";
	$env = array("environment" => "empty");
	$process = proc_open("dot -T$outputformat", $descriptorspec, $pipes, $cwd, $env);
	if (is_resource($process)) {
		// $pipes now looks like this:
		// 0 => writeable handle connected to child stdin
		// 1 => readable handle connected to child stdout
		// 2 => readable handle connected to child stdout
		
		$line=print_graphdata($xDP);
		#$output="DEBUG: feeding $line\n";
		fwrite ($pipes[0], $line);
		fclose($pipes[0]);

		while (!feof($pipes[1])) {
			$output .= fgets($pipes[1]);
		}
		fclose($pipes[1]);

		// It is important that you close any pipes before calling
		// proc_close in order to avoid a deadlock
		$return_value = proc_close($process);
	}
	return $output;
}

function get_settings (){
	$settings="";
	if ($_POST["start_id"] && data_ok($_POST["start_id"],"number")){
		$settings=$_POST["start_id"].".";
	}else{
		return 0;
	}
	if ($_POST["iterations"] && data_ok($_POST["iterations"],"number")){
		$settings.=$_POST["iterations"].".";
	}else{
		return 0;
	}
	if ($_POST["stop_iterations_on_group_change"] && data_ok($_POST["stop_iterations_on_group_change"],"number")){
		$settings.=$_POST["stop_iterations_on_group_change"].".";
	}else{
		$settings.="0.";
	}
	if ($_POST["exclude_devices_from_other_device_groups"] && data_ok($_POST["exclude_devices_from_other_device_groups"],"number")){
		$settings.=$_POST["exclude_devices_from_other_device_groups"].".";
	}else{
		$settings.="0.";
	}
	if ($_POST["only_managed_devices"] && data_ok($_POST["only_managed_devices"],"number")){
		$settings.=$_POST["only_managed_devices"].".";
	}else{
		$settings.="0.";
	}
	return "$settings";
}
function print_graphdata ($xDP){
	$output="digraph g {
graph [
rankdir = \"LR\"
];
";
	$printed_interfaces=array();
	$nodes=array();
	$links=array();
	foreach($xDP as $d_id => $device_data){
		if (is_numeric ($d_id)){
			$data=$GLOBALS["devices"][$d_id];
			$data=explode(".",$data);
			$device_name=$data[0];
			$nodes[$d_id]["hostname"]=$device_name;
			$nodes[$d_id]["shape"]="record";
			$nodes[$d_id]["group"]=$GLOBALS["device_id2group"][$d_id];
			foreach($device_data as $port_id => $remote_data){
				$interface_name=$xDP["interface_data"][$port_id]["ifName"];
				$nodes[$d_id]["interfaces"][$port_id]=$interface_name;
				if (in_array ($port_id, $printed_interfaces)){
					# Only print a link one time.
				}else{
					if (is_numeric ($remote_data)){
						$remote_port_name=$xDP["interface_data"][$remote_data]["ifName"];
						$remote_host_id=$xDP["interface_data"][$remote_data]["device_id"];
						$remote_host_name=$GLOBALS["devices"][$remote_host_id];
						array_push ($printed_interfaces, $port_id, $remote_data);
						$nodes[$remote_host_id]["interfaces"][$remote_data]=$remote_port_name;
						$linkname="\"$d_id\":$port_id -> \"$remote_host_id\":$remote_data";
						$links[$linkname]["dir"]="none";
						$links[$linkname]["weight"]="1";
						if (array_key_exists ("$remote_host_id", $xDP)){
						}else{
							# Remote device is not polled even thoug it is managed.
							# So we create this node aswell
							$nodes[$remote_host_id]["hostname"]=$remote_host_name;
							$nodes[$remote_host_id]["shape"]="record";
							$nodes[$remote_host_id]["group"]=$GLOBALS["device_id2group"][$remote_host_id];
						}
					}else{
						$data=explode(":", $remote_data);
						$fullname=$data[0];
						$name=explode(".",$fullname);
						$short_name=$name[0];
						$nodes[$short_name]["label"]="\"$short_name\"";
						$nodes[$short_name]["shape"]=get_shape($data[1]);
						$linkname="\"$d_id\":$port_id -> \"$short_name\"";
						$links[$linkname]["dir"]="none";
						$links[$linkname]["weight"]="1";
					}//if (is_numeric ($remote_data)){
				}//if (in_array ($port_id, $printed_interfaces){
			}//foreach($device_data as $port_id => $remote_data){
		}//if (is_numeric ($d_id)){
	}//foreach($xDP as $device_id => $device_data){
	foreach($nodes as $d_id => $device_data){
		if ($device_data["shape"]=="record"){
			$output.="\"$d_id\" [\n";
			$label="<$d_id> ".$device_data["hostname"]." "; 
			foreach($device_data["interfaces"] as $interface_id => $interface_name){
				#$stp=$xDP["interface_data"][$interface_id]["stp_state"];
				$stp="";
				if ($xDP["interface_data"][$interface_id]["stp_state"]=="blocking"){
					$stp="stp blocking";
				}elseif ($xDP["interface_data"][$interface_id]["stp_state"]=="disabled"){
					$stp="stp disabled";
				}
				$label.="| <$interface_id> $interface_name $stp";
				#$label.="| <$interface_id> $interface_name ";
			}
			$output.="label = \"$label\"\n";
			foreach($device_data as $key => $value){
				if ($key == "hostname" || $key == "interfaces"){
					# Do nothing, already processed
				}else{
					$output.="$key=$value\n";
				}
			}
			$output.="];\n";
		}else{
			$output.="\"$d_id\" [\n";
			foreach($device_data as $key => $value){
				$output.="$key=$value\n";
			}
			$output.="];\n";
		}//if ($device_data["shape"]=="record"){
	}//foreach($nodes as $d_id => $device_data){
	foreach($links as $link => $link_data){
		$output.="$link [\n";
		foreach ($link_data as $key => $value){
			$output.="$key=$value\n";
		}
		$output.="];\n";
	}
	$output.="}";
	return $output;
}
function get_shape($device_type){
	$default_shape="box";
	$type2shape=array();
	$type2shape["cisco AIR"]="egg";
	$type2shape["Cisco C"]="octagon";
	foreach($type2shape as $type => $shape){
		preg_match("/$type/", $device_type, $matches, PREG_OFFSET_CAPTURE);
		if($matches){
			return $shape;
		}
	}
	return $default_shape;
}

function print_weathermap_data ($xDP){
	$output="";
	$start_x=100;
	$start_y=30;
	$add_x=50;
	$add_y=50;
	$print_x=$start_x;
	$print_y=$start_y;
	$max_x=10;
	$max_y=10;
	$http_server="";
	$printed_interfaces=array();
	$device2hostname=array();
	$multiline_locations=array();
	$multiline_locations["over"][1]="sw,nw";
	$multiline_locations["over"][2]="se,ne";
	$multiline_locations["over"][3]="sw,ne";
	$multiline_locations["over"][4]="se,nw";
	$multiline_locations["under"][1]="nw,sw";
	$multiline_locations["under"][2]="ne,se";
	$multiline_locations["under"][3]="ne,sw";
	$multiline_locations["under"][4]="nw,se";
	if ($_SERVER["SSL_TLS_SNI"]){
		$http_server="https://$_SERVER[SSL_TLS_SNI]";
	}elseif($_SERVER["HTTP_HOST"]){
		$http_server="http://$_SERVER[HTTP_HOST]";
	}else{
		$http_server="unknown/";
	}
	$name4weather="weathermap";
	if ($_POST["name4weather"] && data_ok($_POST["name4weather"], "string")){
		$name4weather=$_POST["name4weather"];
	}else{
		if ($_POST["start_id"]){
			$name4weather=$GLOBALS["devices"][$_POST["start_id"]];
		}
	}
	$query_list="";
	foreach($xDP as $d_id => $device_data){
		$query_list.="device_id='$d_id' OR ";
	}
	$query_list=substr($query_list, 0, -4);
	$query="SELECT device_id,hostname from devices WHERE $query_list;";
	foreach( dbFetchRows($query) as $line){
		$device_id=$line["device_id"];
		$data=$line["hostname"];
		$device2hostname[$device_id]=$data;
	}
	$graphwiz_json=get_graphwiz_data($xDP,"json");
	#print "DEBUG: <pre>";
	#print "$graphwiz_json";
	#print "</pre>\n";
	$graphwiz_array=json_decode($graphwiz_json, "true");
	#print "DEBUG: <pre>";
	#print var_dump($graphwiz_json);
	#print "</pre>\n";
	$node_location=array();
	foreach ($graphwiz_array["objects"] as $obj_id => $obj_array){
		$obj_name=$obj_array["name"];
		$obj_pos=$obj_array["pos"];
		$tmp_array=explode(",",$obj_pos);
		$x=(int)$tmp_array[0];
		$y=(int)$tmp_array[1];
		$node_location[$obj_name]["x"]=$x;
		$node_location[$obj_name]["y"]=$y;
		if ($x > $max_x){
			$max_x=$x;
		}
		if ($y > $max_y){
			$max_y=$y;
		}
	}
	$node_printed=array();
	$weather_data=array();
	foreach($xDP as $d_id => $device_data){
		if (is_numeric ($d_id)){
			$data=$GLOBALS["devices"][$d_id];
			$data=explode(".",$data);
			$device_name=$data[0];
			$print_x=$node_location[$d_id]["x"];
			$print_y=$node_location[$d_id]["y"];
			$node_printed[$d_id]["x"]=$print_x;
			$node_printed[$d_id]["y"]=$print_y;
			$weather_data["nodes"][$d_id]="
NODE $d_id
	LABEL $device_name
	INFOURL $http_server/device/device=$d_id/
	OVERLIBGRAPH $http_server/graph.php?height=100&width=512&device=$d_id&type=device_bits&legend=no
	POSITION $print_x $print_y
";
			$print_x+=$add_x;
			$print_y+=$add_y;
			$multi_line_counter=array();
			foreach($device_data as $port_id => $remote_data){
				$interface_name=$xDP["interface_data"][$port_id]["ifName"];
				$hostname=$device2hostname[$d_id];
				if (in_array ($port_id, $printed_interfaces)){
					# Only print a link one time.
				}else{
					if (is_numeric ($remote_data)){
						$remote_port_name=$xDP["interface_data"][$remote_data]["ifName"];
						$remote_host_id=$xDP["interface_data"][$remote_data]["device_id"];
						$remote_host_name=$GLOBALS["devices"][$remote_host_id];
						array_push ($printed_interfaces, $port_id, $remote_data);
						if ($weather_data["links"]["$d_id-$remote_host_id"]){
							$multi_line_counter["$d_id-$remote_host_id"]++;
							$node1_y=$node_location[$d_id]["y"];
							$node2_y=$node_location[$remote_host_id]["y"];
							$location=get_location($node_location[$d_id]["y"],$node_location[$remote_host_id]["y"]);
							if ($multiline_locations[$location][$multi_line_counter["$d_id-$remote_host_id"]]){
								$locations=$multiline_locations[$location][$multi_line_counter["$d_id-$remote_host_id"]];
								$node_connections=explode(",",$locations);
								$node1_connect=":$node_connections[0]";
								$node2_connect=":$node_connections[1]";
							}else{
								$node1_connect="";
								$name=$multi_line_counter["$d_id-$remote_host_id"];
								$node2_connect="
#WARNING: More connections than defined in \$multiline_locations array! This will not be visible! DEBUG: \$location=$location ($node1_y, $node2_y) \$multi_line_counter=$name";
							}
							$weather_data["links"]["$d_id-$port_id-$remote_host_id"]="
LINK $d_id-$port_id-$remote_host_id
        INFOURL $http_server/graphs/type=port_bits/id=$port_id/
        OVERLIBGRAPH $http_server/graph.php?height=100&width=512&id=$port_id&type=port_bits&legend=no
        TARGET ./$hostname/port-id$port_id.rrd:INOCTETS:OUTOCTETS
        MAXVALUE ${MAXspeed}M
        NODES $d_id$node1_connect $remote_host_id$node2_connect
";
						}else{
							$weather_data["links"]["$d_id-$remote_host_id"]="
LINK $d_id-$remote_host_id
	INFOURL $http_server/graphs/type=port_bits/id=$port_id/
	OVERLIBGRAPH $http_server/graph.php?height=100&width=512&id=$port_id&type=port_bits&legend=no
	TARGET ./$hostname/port-id$port_id.rrd:INOCTETS:OUTOCTETS
	MAXVALUE ${MAXspeed}M
	NODES $d_id $remote_host_id
";
						}
						if ($node_printed[$remote_host_id]){
							# Connected node already printed
						}else{
							#$device_name=$node_label[$remote_host_id];
							$query="SELECT sysName from devices WHERE device_id='$remote_host_id';";
							foreach( dbFetchRows($query) as $line){
								$device_name=$line["sysName"];
							}
							$tmp=explode('.', $device_name);
							$device_name=$tmp[0];
							$print_x=$node_location[$remote_host_id]["x"];
							$print_y=$node_location[$remote_host_id]["y"];
							$node_printed[$remote_host_id]["x"]=$print_x;
							$node_printed[$remote_host_id]["y"]=$print_y;
							$weather_data["nodes"][$remote_host_id]="
NODE $remote_host_id
	LABEL $device_name
	INFOURL $http_server/device/device=$remote_host_id/
	OVERLIBGRAPH $http_server/graph.php?height=100&width=512&device=$remote_host_id&type=device_bits&legend=no
	POSITION $print_x $print_y
";
						}
					}else{
						$neibour=explode(":", $remote_data);
						$data=$neibour[0];
						$data=explode(".",$data);
						$nodename=$data[0];
						$tmp_print_x=$node_location[$nodename]["x"];
						$tmp_print_y=$node_location[$nodename]["y"];
						$MAXspeed=$xDP["interface_data"][$port_id]["ifHighSpeed"];
						$weather_data["nodes"][$nodename]="
NODE $nodename
	LABEL $nodename
	POSITION $tmp_print_x $tmp_print_y
	INFOURL $http_server/graphs/type=port_bits/id=$port_id/
	OVERLIBGRAPH $http_server/graph.php?height=100&width=512&id=$port_id&type=port_bits&legend=no
	TARGET ./$hostname/port-id$port_id.rrd:INOCTETS:OUTOCTETS
	MAXVALUE ${MAXspeed}M
";
						if ($weather_data["links"]["$d_id-$nodename"]){
							#$weather_data["multi_links"]["$d_id-$nodename"]["$d_id$port_id-$nodename"]=1;
							$multi_line_counter["$d_id-$nodename"]++;
							$node1_y=$node_location[$d_id]["y"];
							$node2_y=$node_location[$nodename]["y"];
							$location=get_location($node_location[$d_id]["y"],$node_location[$nodename]["y"]);
							if ($multiline_locations[$location][$multi_line_counter["$d_id-$nodename"]]){
								$locations=$multiline_locations[$location][$multi_line_counter["$d_id-$nodename"]];
								$node_connections=explode(",",$locations);
								$node1_connect=":$node_connections[0]";
								$node2_connect=":$node_connections[1]";
							}else{
								$node1_connect="";
								$name=$multi_line_counter["$d_id-$nodename"];
								$node2_connect="
#WARNING: More connections than defined in \$multiline_locations array! This will not be visible!  DEBUG: \$location=$location ($node1_y,$node2_y) \$multi_line_counter=$name";
							}
							$weather_data["links"]["$d_id$port_id-$nodename"]="
LINK $d_id-$port_id-$nodename
	INFOURL $http_server/graphs/type=port_bits/id=$port_id/
	OVERLIBGRAPH $http_server/graph.php?height=100&width=512&id=$port_id&type=port_bits&legend=no
	TARGET ./$hostname/port-id$port_id.rrd:INOCTETS:OUTOCTETS
	MAXVALUE ${MAXspeed}M
	NODES $d_id$node1_connect $nodename$node2_connect
";
	#NODES $d_id $nodename
						}else{
							$weather_data["links"]["$d_id-$nodename"]="
LINK $d_id-$nodename
	INFOURL $http_server/graphs/type=port_bits/id=$port_id/
	OVERLIBGRAPH $http_server/graph.php?height=100&width=512&id=$port_id&type=port_bits&legend=no
	TARGET ./$hostname/port-id$port_id.rrd:INOCTETS:OUTOCTETS
	MAXVALUE ${MAXspeed}M
	NODES $d_id $nodename
";
						}//if ($weather_data[links]["$d_id-$nodename"]){
					}//if (is_numeric ($remote_data)){}else{}
				}//if (in_array ($port_id, $printed_interfaces){
			}//foreach($device_data as $port_id => $remote_data){
		}//if (is_numeric ($d_id)){
	}//foreach($xDP as $device_id => $device_data){
	#print "DEBUG:<pre>";
	#var_dump($weather_data);
	#print "</pre>";
	$max_x+=70; # Add some for label
	$max_y+=20; # Add some for label
	$output.="
WIDTH $max_x
HEIGHT $max_y

TITLE $name4weather
HTMLSTYLE overlib
HTMLOUTPUTFILE output/$name4weather.html
IMAGEOUTPUTFILE output/$name4weather.png
TIMEPOS 5 13 $name4weather Created: %b %d %Y %H:%M:%S

KEYPOS DEFAULT -1 -1 Traffic Load
KEYTEXTCOLOR 0 0 0
KEYOUTLINECOLOR 0 0 0
KEYBGCOLOR 255 255 255
BGCOLOR 255 255 255
TITLECOLOR 0 0 0
TIMECOLOR 0 0 0
SCALE DEFAULT 0    0    192 192 192
SCALE DEFAULT 0    1    255 255 255
SCALE DEFAULT 1    10   140   0 255
SCALE DEFAULT 10   25    32  32 255
SCALE DEFAULT 25   40     0 192 255
SCALE DEFAULT 40   55     0 240   0
SCALE DEFAULT 55   70   240 240   0
SCALE DEFAULT 70   85   255 192   0
SCALE DEFAULT 85   100  255   0   0

SET key_hidezero_DEFAULT 1

NODE DEFAULT
	MAXVALUE 100

LINK DEFAULT
	WIDTH 2
	BANDWIDTH 1000M
";
	foreach($weather_data["nodes"] as $nodename => $nodedata){
		$output.="$nodedata";
	}
	foreach($weather_data["links"] as $linksname => $linksdata){
		$output.="$linksdata";
	}
	return $output;
}
function get_location ($node1_y,$node2_y){
	$location="over"; # Set default to over
	if ($node1_y < $node2_y){
		$location="under";
	}
	return $location;
}

function print_connections ($xDP){
	$output="<b>name:interface(interface id)=>cdp_neibour_name:xdp_device_type:xdp_device_connected_interface</b><br>\n";
	$printed_interfaces=array();
	foreach($xDP as $d_id => $device_data){
		if (is_numeric ($d_id)){
			$device_name=$GLOBALS["devices"][$d_id];
#			print "$device_name<br>\n";
			foreach($device_data as $port_id => $remote_data){
				$interface_name=$xDP["interface_data"][$port_id]["ifName"];
				if (in_array ($port_id, $printed_interfaces)){
					# Only print a link one time.
				}else{
					$output.= "$device_name:$interface_name($port_id)=>";
					if (is_numeric ($remote_data)){
						#To be done
						#print "$remote_data managed interface id<br>\n";
						$remote_port_name=$xDP["interface_data"][$remote_data]["ifName"];
						$remote_host_id=$xDP["interface_data"][$remote_data]["device_id"];
						$remote_host_name=$GLOBALS["devices"][$remote_host_id];
						$output.= "$remote_host_name:$remote_port_name($remote_data)<br>\n";
						array_push ($printed_interfaces, $port_id, $remote_data);
					}else{
						$output.= "$remote_data<br>\n";
					}
				}//if (in_array ($port_id, $printed_interfaces){
			}//foreach($device_data as $port_id => $remote_data){
		}//if (is_numeric ($d_id)){
	}//foreach($xDP as $device_id => $device_data){
	return $output;
}
function do_xDP_check ($device_id, $xDP, $iterations){
	if ($_POST["iterations"] && data_ok($_POST["iterations"],"number")){
		$max_iterations=$_POST["iterations"];
	}

	$iterations++;
#	print "DEBUG: do_xDP_check($device_id, \$xDP, $iterations) \$max_iterations=$max_iterations<br>";
	if ($iterations <= $max_iterations){
		# Check if already done check for this device.
		if (!in_array ($device_id, $devices_run)){
#			print "DEBUG: Doing xDP lookup for \$device_id=$device_id ()<br>\n";
			array_push($devices_run, $device_id);
			$query = "SELECT links.local_port_id,
		links.local_device_id,
		links.protocol,
		links.remote_port_id,
		ports.ifName,
		links.remote_hostname,
		links.remote_device_id,
		links.remote_port,
		links.remote_platform,
		links.remote_version
FROM links,ports
WHERE
local_device_id='$device_id'
AND
links.local_port_id=ports.port_id;";
			foreach( dbFetchRows($query) as $line){
				$local_device_id=$line["local_device_id"];
				$local_port_id=$line["local_port_id"];
				$local_ifName=$line["ifName"];
				$remote_port_id=$line["remote_port_id"];
				$protocol=$line["protocol"];
				$remote_hostname=$line["remote_hostname"];
				$remote_device_id=$line["remote_device_id"];
				$remote_port=$line["remote_port"];
				$remote_platform=$line["remote_platform"];
				$remote_version=$line["remote_version"];
				#$port_id2name[$local_port_id]=$local_ifName;
				if ($remote_port_id){
					# Remote port is a libreNMS managed device.
#					print "DEBUG: local_group: ".$GLOBALS[device_id2group][$local_device_id]." remote_group: ".$GLOBALS["device_id2group"][$remote_device_id]."<br>";
					$local_gid=$GLOBALS["device_id2group"][$local_device_id];
					$remote_gid=$GLOBALS["device_id2group"][$remote_device_id];
					$same_group=0;
					if ($local_gid == $remote_gid){
						$same_group=1;
					}
					$xDP["interface_data"][$local_port_id]["neibour"]=$remote_port_id;
					$xDP["interface_data"][$remote_port_id]["neibour"]=$local_port_id;
					if ($same_group){
						$xDP[$local_device_id][$local_port_id]=$remote_port_id;
					}else{
						if ($_POST["exclude_devices_from_other_device_groups"] && data_ok($_POST["exclude_devices_from_other_device_groups"],"number")){
							# Not in same group, and output from outher group memebers should be excluded.
						}else{
							$xDP[$local_device_id][$local_port_id]=$remote_port_id;
						}
					}
					if (array_key_exists ($remote_device_id, $xDP)){
						#Already polled this id
					}else{
						if ($same_group){
							if ($xDP[$remote_device_id]){
								# Remote device is already polled.
							}else{
								$xDP=do_xDP_check($remote_device_id, $xDP, $iterations);
							}
						}else{
							if ($_POST["stop_iterations_on_group_change"] && data_ok($_POST["stop_iterations_on_group_change"],"number")){
								# Stop iteration on group change
							}else{
								if ($xDP[$remote_device_id]){
									# Remote device is already polled.
								}else{
									$xDP=do_xDP_check($remote_device_id, $xDP, $iterations);
									#print "DEBUG: \$xDP[$local_device_id][$local_port_id]=$remote_port_id;<br>\n";
								}
							}
						}
					}
				}else{
					if ($_POST["only_managed_devices"] && data_ok($_POST["only_managed_devices"],"number")){
						# Only add managed devices.
					}else{
						$print_this=1;
						if ($_POST["exclude_name"] && data_ok($_POST["exclude_name"], "string")){
							$exclude_name=$_POST["exclude_name"];
							if (preg_match ( "[$exclude_name]", $remote_hostname)){
								$print_this=0;
							}
						}
						if ($_POST["exclude_type"] && data_ok($_POST["exclude_type"], "string")){
							$exclude_type=$_POST["exclude_type"];
							if (preg_match ( "[$exclude_type]", $remote_platform)){
								$print_this=0;
							}
						}
						
						if ($print_this){
							$xDP[$local_device_id][$local_port_id]="$remote_hostname:$remote_platform:$remote_port";
							$xDP["interface_data"][$local_port_id]["neibour"]="$remote_hostname:$remote_port";
							#print "DEBUG: \$xDP[$local_device_id][$local_port_id]="$remote_hostname:$remote_platform:$remote_port"<br>\n";
						}
					}
				}
			}//foreach( dbFetchRows($query) as $line){
			#print "stuff<br>\n";
		}//if (in_array ($device_id, $devices_run)){
	}else{//if ($iterations <= $max_iterations){
		$device_name=$GLOBALS["devices"][$device_id];
		if ($GLOBALS["name_printed"][$device_name]){
			# Already printed...
		}else{
			print "INFO: iteration $iterations exeeded max iterations $max_iterations, skipping poll of $device_name<br>\n";
			$GLOBALS["name_printed"][$device_name]++;
		}
	}
	return $xDP;
}//function do_xDP_check ($device_id, $iterations){

function get_interface_data ($xDP){
	$query_interface_list="";
	foreach ($xDP["interface_data"] as $interface_id => $interface_array){
		$query_interface_list.="port_id='$interface_id' OR ";
	}
	$query_interface_list=substr($query_interface_list, 0, -4);
	$query="SELECT * from ports WHERE $query_interface_list;";
	#print "DEBUG: $query<br>";
	foreach( dbFetchRows($query) as $line){
		$interface_id=$line["port_id"];
		foreach($line as $value => $data){
			$xDP["interface_data"][$interface_id][$value]=$data;
		}
	}
	$query="SELECT device_id,port_id,state from ports_stp WHERE $query_interface_list;";
	foreach( dbFetchRows($query) as $line){
		$device_id=$line["device_id"];
		$port_id=$line["port_id"];
		$state=$line["state"];
		if ($xDP["interface_data"][$port_id]["device_id"]==$device_id){
			$xDP["interface_data"][$port_id]["stp_state"]=$state;
		}else{
			print "ERROR: STP device_id for port_id $port_id differ from port table.<br>\n";
		}
	}
	return $xDP;
}

function data_ok ($data, $validate_type, $test_with_this=""){
	$illegal_characters="'\";:\/";
	if ($validate_type == "number"){
		if (is_numeric($data)){
			return 1;
		}
	}elseif ($validate_type == "string"){
		if (preg_match ( "/[$illegal_characters]/", $data)){
			return 0;
		}else{
			return 1;
		}
	}elseif ($validate_type == "custom"){
		if ($test_with_this){
			#print "DEBUG: data_ok ('$data', '$validate_type', '$test_with_this')=";
			if (preg_match ("$test_with_this", $data)){
			#	print "1<br>\n";
				return 1;
			}else{
			#	print "0<br>\n";
				return 0;
			}
		}else{
			return 0;
		}
	}else{
		return 0;
	}
	return 0;
}

function print_form(){
	$query = "SELECT device_group_id,device_id  FROM device_group_device";
	foreach( dbFetchRows($query) as $line){
		$device_id=$line["device_id"];
		$device_group_id=$line["device_group_id"];
		$GLOBALS["device_id2group"]["$device_id"]="$device_group_id";
		$GLOBALS["devices_in_group"]["$device_group_id"]["$device_id"]++;
	}

	$query = "SELECT DISTINCT
	links.local_device_id,devices.sysName
	FROM links,devices
	WHERE
	links.local_device_id=devices.device_id
	ORDER BY devices.sysName;";
	$tablecolumn1.=" <select name='start_id'> ";
	foreach( dbFetchRows($query) as $line){
		$id=$line["local_device_id"];
		$sysName=$line["sysName"];
		$GLOBALS["devices"][$id]=$sysName;
		$selected="";
		if ($_POST["start_id"] == $id){
			$selected="selected";
		}
		$tablecolumn1.="<option value='$id' $selected>$sysName</option>";
	}
	$tablecolumn1.=" </select> ";

	$max_iterations=$GLOBALS["max_iterations"];
	if ($_POST["iterations"] && data_ok($_POST["iterations"],"number")){
		$max_iterations=$_POST["iterations"];
	}
	$only_managed_devices_checked="";
	if ($_POST["only_managed_devices"] && data_ok($_POST["only_managed_devices"],"number")){
		$only_managed_devices_checked="checked";
	}else{
		$only_managed_devices_checked="";
	}
	$exclude_devices_from_other_device_groups="";
	if ($_POST["exclude_devices_from_other_device_groups"] && data_ok($_POST["exclude_devices_from_other_device_groups"],"number")){
		$exclude_devices_from_other_device_groups="checked";
	}else{
		$exclude_devices_from_other_device_groups="";
	}
	$stop_iterations_on_group_change="";
	if ($_POST["stop_iterations_on_group_change"] && data_ok($_POST["stop_iterations_on_group_change"],"number")){
		$stop_iterations_on_group_change="checked";
	}else{
		if ($_POST["start_id"]){
			$stop_iterations_on_group_change="";
		}else{
			# Default checked
			$stop_iterations_on_group_change="checked";
		}
	}
	$name4weather="";
	if ($_POST["name4weather"] && data_ok($_POST["name4weather"], "string")){
		$name4weather=$_POST["name4weather"];
	}else{
		if ($_POST["start_id"]){
			$name4weather=$GLOBALS["devices"][$_POST["start_id"]];
		}
	}
	if ($_POST["include_port_id"] && data_ok($_POST["include_port_id"],"custom","/^[0-9,]*$/")){
		$include_port_id=$_POST["include_port_id"];
	}else{
		$include_port_id="";
	}
	$exclude_name="";
	if ($_POST["exclude_name"] && data_ok($_POST["exclude_name"], "string")){
		$exclude_name=$_POST["exclude_name"];
	}
	$exclude_type="";
	if ($_POST["exclude_type"] && data_ok($_POST["exclude_type"], "string")){
		$exclude_type=$_POST["exclude_type"];
	}
	if ($_POST["settings"]){
		if ($_POST["settings"] == "advanced_settings"){
		}elseif($_POST["settings"] == "simple_settings"){
		}else{
		}
	}else{
		$settings="simple_settings";
	}

	$tablecolumn1.="<br>Max iterations: <input name='iterations' type='text' value='$max_iterations'><br> ";
	$tablecolumn1.="Stop iterations when device group change: <input type='checkbox' name='stop_iterations_on_group_change' $stop_iterations_on_group_change value='1'><br>";
	$tablecolumn1.="Exclude managed devices from other device_group in output: <input type='checkbox' name='exclude_devices_from_other_device_groups' $exclude_devices_from_other_device_groups value='1'><br> ";
	$tablecolumn1.="Only include fully managed devices: <input type='checkbox' name='only_managed_devices' $only_managed_devices_checked value='1'><br> ";
	$tablecolumn1.="<br><input name='display' value='print_connections' type='submit'>
		<input name='display' value='print_graphdata' type='submit'>
		<input name='display' value='print_weathermap_data' type='submit'>
		<!-- <input name='settings' value='$settings' type='submit'> -->
		<br>";
	$tablecolumn2.="Name for Weathermap: <input name='name4weather' type='text' value='$name4weather'><br>";
	$tablecolumn2.="Include this port ids (separate with ,): <input name='include_port_id' type='text' value='$include_port_id'><br>";
	$tablecolumn2.="Exclude unmanaged neibours where device type match: <input name='exclude_type' type='text' value='$exclude_type'><br>";
	$tablecolumn2.="Exclude unmanaged neibours where device name match: <input name='exclude_name' type='text' value='$exclude_name'><br>";
	print "Select device to start map from:";
	print " <form action='/plugin/p=".$GLOBALS["plugin_name"]."' method='post'> ";
	print csrf_field();
	#print $tablecolumn1;
	print "<table><tr><td>$tablecolumn1<td>$tablecolumn2</tr></table>";
	print " </form> ";
}
