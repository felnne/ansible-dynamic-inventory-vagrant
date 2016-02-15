<?php

/*
 * Vagrant Ansible dynamic inventory
 *
 * This script is designed to act as an Ansible dynamic inventory, each time it is run a instantaneous representation
 * of the current Vagrant environment is returned.
 *
 * Maintainer: Felix Fennell <felnne@bas.ac.uk>, Web & Applications Team <webapps@bas.ac.uk>, British Antarctic Survey
 *
 * Note: This script is a proof-of-concept!
 * Note: A Vagrant environment needs to be running before running this script (i.e. you've already run '$ vagrant up')
 * Note: If you want to see what this inventory will look like you can run this script directly.
*/

/*
 * Initialisation
 */

/*
 * Setup the data-structure that will hold information about hosts and how to connect to them
 * This is used to build host information in the dynamic inventory
 */
$hosts = [];
/*
 * Set the data-structure that will hold information how hosts can be grouped together
 * This is used to build group information in the dynamic inventory
 */
$groups = [];

/*
 * Also setup data-structures for holding any errors or warnings encountered
 * Fatal errors will be returned immediately, otherwise they will be returned at the end as comments in the inventory
 *
 * TODO: Add exceptions and logging support for this
 */
$errors = [];
$warnings = [];

/*
 * Get output from Vagrant for use as input in this script
 */

/*
 * Start by setting the working directory, this needs to be path that contains the 'Vagrantfile'
 * Typically the 'Vagrantfile' will be in the root of a project, this script will be in 'provisioning/inventories'
 * By default we will therefore default to '../../' to change to the project root
 *
 * As a backup we will also record the current working directory in case we need to change back to it in the future
 */
$startupWorkingDirectory = getcwd();
$workingDirectory = '../../';

// Debug
$workingDirectory = '/Users/felnne/Projects/WebApps/Web-Applications-Project-Template/';

chdir($workingDirectory);

/*
 * Now we can call the 'Vagrant ssh-config --machine-readable' command, this returns information on the current hosts
 * including their name, provider ('virtualbox', 'vmware_fusion', etc.) and SSH details, such as the private key
 */
$vagrantOutput = shell_exec('vagrant ssh-config --machine-readable');

/*
 * Next we need to split the Vagrant output into lines, not all of which will make sense and will need filtering later
 */
$input = explode("\n", $vagrantOutput);

/*
 * Now convert each line from a 'serialised' array of fields, to a multi-dimensional array to make processing easier
 * Again not all of these lines are valid messages
 */
$input2 = [];

// TODO: Replace with 'map()'
foreach($input as $line) {
    $input2[] = explode(',', $line);
}

/*
 * Message validation
 */

/*
 * Try to interpret each line of output from Vagrant as a 'message' to build up host information
 * Any messages we don't understand (usually because they aren't a valid message), or which don't refer to a host,
 * we save for potential processing in the future
 *
 * The format of a valid message is defined by Vagrant: https://www.vagrantup.com/docs/cli/machine-readable.html
 *
 * Each $message will consist of several properties:
 * - $message[0] - timestamp - we can ignore this
 * - $message[1] - target - AKA hostname, we use this to select the relevant index in the $hosts array
 * - $message[2] - type - we are only interested in "metadata" and "ssh-config" type messages
 * - $message[3] - data - either the metadata key, if the message type is "metadata", or ssh-config information if the
 *   message type is "ssh-config"
 * - $message[4] - data - will be the value for the relevant metadata key, if the message type is "metadata"
 */

/*
 * First we filter out messages that don't have < 4 indexes (timestamp, target, type and data), as these are malformed
 * We will check if the values for each of these indexes are set later, and deal with any malformed messages at the end
 */
$input3 = [];
$malformedMessages = [];

foreach($input2 as $message) {
    if (count($message) >= 4) {
        $input3[] = $message;
    } else {
        $malformedMessages[] = $message;
    }
}

/*
 * Next we filter out any messages that don't specify a host ($message[1]), as these are global messages or errors
 * We will deal with any of these non-host specific messages at the end
 */
$input4 = [];
$nonSpecificMessages = [];

foreach($input3 as $message) {
    if ($message[1] != '' && $message[1] != null) {
        $input4[] = $message;
    } else {
        $nonSpecificMessages[] = $input4;
    }
}

/*
 * Next we filter out any messages that aren't of type 'metadata' or 'ssh-config', as these aren't interesting to us
 * - 'metadata' messages contain the name of the provider for a VM (which we use as a group in the Ansible inventory)
 * - 'ssh-config' messages contain the private key we need to connect to each host and need in the inventory
 *
 * For 'metadata' messages, $message[3] will be the metadata key, we filter this as well as we are only interested in
 * the 'provider' key.
 *
 * We deal with any other, non-interesting, types of message, or non-interesting metadata messages at the end
 */
$input5 = [];
$nonInterestingMessages = [];

foreach($input4 as $message) {
    // TODO: Convert to array of 'interestingMessageTypes'
    if ($message[2] == 'metadata' || 'ssh-config') {

        if ($message[2] == 'provider') {
            // TODO: Convert to array of 'interestingMetadataKeys'
            if ($message[3] == 'provider') {
                $input5[] = $message;
            } else {
                $nonInterestingMessages[] = $message;
            }
        } else {
            // This can only mean its of type 'ssh-config'
            $input5[] = $message;
        }
    } else {
        $nonInterestingMessages[] = $message;
    }
}

/*
 * Message processing
 */

/*
 * Next we will build up an index of hosts which we have information for, from the messages we know relate to a host,
 * and which are known to be interesting
 */

foreach ($input5 as $message) {
    if (! array_key_exists($message[1], $hosts)) {
        $hosts[$message[1]] = [];
    }
}

/*
 * Next we fill in information about each host from the interesting messages - we only know how to do this for metadata
 * provider (i.e. metadata for the host's provider) and ssh-config type messages
 *
 * - For 'provider metadata', $message[4] is the provider for the host (e.g. 'vmware_fusion')
 * - For 'ssh-config', $message[3] is the SSH configuration information (repeated twice in different forms)
 */
foreach ($input5 as $message) {
    if ($message[2] == 'metadata' && $message[3] == 'provider') {
        // Record actual value for possible future use
        $hosts[$message[1]]['provider_raw'] = $message[4];

        // Normalise 'provider' value
        if ($message[4] == 'vmware_fusion') {
            $hosts[$message[1]]['provider'] = 'vmware_desktop';
        }
    } else if ($message[2] == 'ssh-config') {
        // For some reason Vagrant gives the SSH connection information as both a string with newlines, and a formatted
        // string. We don't actually care which is used, but we do care that everything ends up being repeated.
        // We therefore ignore the second set of information by removing it.
        $messageValue = explode('FATAL', $message[3]);
        $messageValue = $messageValue[0];

        // Get the name of the host (e.g. 'foo-dev-node1') which translates to the short hostname (e.g. unqualified)
        $hosts[$message[1]]['hostname'] = strip_string(get_string_between($messageValue, 'Host', 'HostName'));

        // Get the path to the private key needed to connect to the host, which Vagrant will generate automatically
        $hosts[$message[1]]['identity_file'] = strip_string(get_string_between($messageValue, 'IdentityFile', 'IdentitiesOnly'));

        // Generate a Fully Qualified Domain Name (FQDN) for the hostname by appending a common domain name
        $domainName = '.v.m';

        $hosts[$message[1]]['fqdn'] = $hosts[$message[1]]['hostname'] . $domainName;
    }
}

/*
 * Next we use the information we know about hosts and generate a series of groupings, which will be exposed as in the
 * generated inventory as Ansible Groups - these include:
 * - 'manager' (Vagrant in this case)
 * - 'provider' (usually 'vmware' for BAS projects)
 * - 'project' - this can be determined if the hostname is formatted according to WSR-1, otherwise skipped
 * - 'environment' - this can be determined if the hostname is formatted according to WSR-1, otherwise skipped
 * - 'instance' - this can be determined if the hostname is formatted according to WSR-1, otherwise skipped
 * - 'node' - this can be determined if the hostname is formatted according to WSR-1, otherwise skipped
 *
 * Note: For groups which rely on WSR-1 formatted hostname's, groups are skipped if no suitable hostname's are found
 */

/*
 * Since this is a Vagrant dynamic inventory, all hosts are assumed to be in part of a 'vagrant' group
 */
$groups['vagrant'] = [];

//Hosts are added using their FQDN as an identifier
// TODO: Use a map for this
foreach ($hosts as $host) {
    $groups['vagrant'][] = $host['fqdn'];
}

/*
 * Vagrant tells us the provider for each host, so we can build a group for each provider
 */
foreach($hosts as $host) {
    // Create group for provider if it does not already exist
    if (! array_key_exists($host['provider'], $groups)) {
        $groups[$host['provider']] = [];
    }

    $groups[$host['provider']][] = $host['fqdn'];
}

/*
 * If a host uses a WSR-1 compliant hostname we can infer extra information about:
 * - The project the host belongs to
 * - The environment (dev/prod etc.) the host belongs to
 * - The instance of the project (if applicable) the host belongs to
 * - The function/purpose of the host and its index (i.e. 1 for the first database server, 2 for the second etc.)
 *
 * This can be used to make extra groups
 */
foreach ($hosts as $hostName => $hostDetails) {
    $hostnameWSRDecoded = decode_wsr_1_hostname($hostName);
    $hostnameNodeDecoded = decode_wsr_1_node($hostnameWSRDecoded['node']);

    if ($hostnameWSRDecoded !== false) {
        // Project
        if (! array_key_exists($hostnameWSRDecoded['project'], $groups)) {
            $groups[$hostnameWSRDecoded['project']] = [];
        }
        $groups[$hostnameWSRDecoded['project']][] = $hostName;

        // Environment
        if (! array_key_exists($hostnameWSRDecoded['environment'], $groups)) {
            $groups[$hostnameWSRDecoded['environment']] = [];
        }
        $groups[$hostnameWSRDecoded['environment']][] = $hostName;

        // Instance (if applicable)
        if ($hostnameWSRDecoded['instance'] !== null) {
            if (! array_key_exists($hostnameWSRDecoded['instance'], $groups)) {
                $groups[$hostnameWSRDecoded['project']] = [];
            }
            $groups[$hostnameWSRDecoded['project']][] = $hostName;
        }
    }

    if ($hostnameNodeDecoded !== false) {
        // Node purpose
        if (! array_key_exists($hostnameNodeDecoded['purpose'], $groups)) {
            $groups[$hostnameNodeDecoded['purpose']] = [];
        }
        $groups[$hostnameNodeDecoded['purpose']][] = $hostName;
    }
}

/*
 * Inventory construction
 */

/*
 * The inventory will be built using an array to make adding construction easier, each array item can be considered a
 * line in the resulting string representation of the array, which is generated using 'implode()'
 */
$inventory = [];

/*
 * First some minimal 'introduction' comments
 */
$inventory[] = '# Ansible Vagrant dynamic inventory';
$inventory[] = '# The contents of this inventory are generated by a script, do not modify manually';

/*
 * Next we define details on each host, specifically their FQDN and the SSH private key (identity file)
 *
 * Note: Roles such as 'barc-ansible-roles-collection.system-hostname' (based on 'ANXS.hostname') will automatically
 *       set a relevant hostname based on the host's FQDN
 */
$inventory[] = "\n";
$inventory[] = '## Host definitions';

foreach ($hosts as $host) {
    $line = $host['fqdn'];

    // If an identity file is defined append to the host definition
    if (array_key_exists('identity_file', $host) && $host['identity_file'] != '') {
        $line .= " ansible_ssh_private_key_file='" . $host['identity_file'] . "'";
    }

    $inventory[] = $line;
}

/*
 * Next we define the various groups we built earlier, if a group has no members (which can be hosts or other groups)
 * we omit it
 */
$inventory[] = "";
$inventory[] = '## Group definitions';

// TODO: Replace with map()?
foreach($groups as $groupName => $groupMembers) {
    $inventory[] = '[' . $groupName . ']';

    // Merge in members (hosts or group names) of group - empty or falsy values are omitted
    $inventory = array_merge($inventory, array_filter($groupMembers));

    // Add separator after each group
    $inventory[] = '';
}

// Remove trailing separator
array_pop($inventory);

/*
 * End the inventory with an empty line
 */
$inventory[] = "\n";

/*
 * Finally, output the inventory as an imploded array glued with new lines
 */
echo implode("\n", $inventory);

/*
 * Utility functions
 */

// Takes an input $string, and returns the substring between a $start string and a $end string
// E.g. get_string_between('Foo Bar Baz', 'Foo', 'Baz') returns ' Bar ')
// It is recommended to trim the return value of this function to remove leading/trailing spaces
//
// TODO: Convert to DocBlock
function get_string_between($string, $start, $end){
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}

// Takes an input $string, and returns it without leading/trailing spaces, and optionally, without single/double quotes
// or new lines
// Quote and new line stripping are enabled by default
// E.g. trim_string(' "foo" \n bar ') returns 'foo bar'
//
// TODO: Convert to DocBlock
function strip_string($string, $strip_quotes = true, $strip_newlines = true) {
    if ($strip_quotes) {
        // Strip single or double quotes
        $string = str_replace('"', '', str_replace("'", "", $string));
    }
    if ($strip_newlines) {
        // Strip \n and \r
        $string = str_replace('\n', '', str_replace("'", "", $string));
        $string = str_replace('\r', '', str_replace("'", "", $string));
    }

    // Strip leading/trailing spaces
    return ltrim(rtrim($string));
}

// Takes an input $hostname and determines if it is compliant with the WSR-1 hostname naming convention
// If it is details on the project, environment, instance (if applicable) and node will be returned
// If the hostname is not WSR-1 compliant a value of 'false' will be returned
// If an instance is not defined, a null value will be set for that element
//
// E.g. decode_wsr_1_hostname('pristine-wonderment-of-the-ages-dev-felnne-db1') returns:
// (Array - [Key] = value)
//  [project]     = pristine-wonderment-of-the-ages
//  [environment] = dev
//  [instance]    = felnne
//  [node]        = db1
//
// TODO: Convert to DocBlock
function decode_wsr_1_hostname($hostname) {
    $project = null;
    $environment = null;
    $instance = null;
    $node = null;

    // The list of valid values for the environment of a WSR-1 hostname are controlled
    $validEnvironments = [
        'dev',
        'stage',
        'test',
        'demo',
        'prod'
    ];

    // WSR-1 hostname's use '-' to separate elements
    $elements = explode('-', $hostname);

    // $project, $environment and $instance are required - so if there aren't at least this many elements this hostname
    // is not WSR-1 compliant
    if (count($elements) < 3) {
        return false;
    }

    // The options for the $environment are a controlled list, and therefore predictable, we can then use the position
    // of where the environment appears to workout the $project (which will come before the environment) and the
    // $instance and $node which will appear afterwards

    // First check a valid environment was used
    if (empty(array_intersect($validEnvironments, $elements))) {
        return false;
    }

    // Second find which environment is used and where is appears in the array
    // TODO: Replace with map()?
    $environmentIndex = false;
    foreach ($validEnvironments as $validEnvironment) {
        $environmentIndexInstance = array_search($validEnvironment, $elements);

        if ($environmentIndexInstance !== false) {
            $environmentIndex = $environmentIndexInstance;
            $environment = $elements[$environmentIndex];
        }
    }

    // Determine the project name by taking all hostname elements before the environment element
    $project = implode('-', array_chunk($elements, $environmentIndex)[0]);

    // Determine the project instance by taking the elements between the environment and the node (the last element)
    $instanceElements = array_chunk($elements, $environmentIndex)[1];
    array_pop($instanceElements);
    array_shift($instanceElements);
    // If there are any elements left, these are instance elements and should be combined together, otherwise omit
    if (count($instanceElements) > 0) {
        $instance = implode('-', $instanceElements);
    }

    // Determine the node name by taking the last element
    $node = $elements[count($elements) - 1];

    // We can now package up the WSR elements and return them as an array
    $output = [
        'project' => $project,
        'environment' => $environment,
        'instance' => $instance,
        'node' => $node
    ];
    return $output;
}

// Takes an input $nodeName and if it is compliant with the WSR-1 hostname naming convention returns the name/purpose
// (database server) of a node, and its index (3rd database server)
// If the node name is not WSR-1 compliant a vale of 'false' will be returned
//
// E.g. decode_wsr_1_node('db1') returns:
// (Array - [Key] = value)
//  [purpose]     = db
//  [index]       = 1
//
// TODO: Convert to DocBlock
function decode_wsr_1_node($nodeName) {
    if ($nodeName == null) {
        return false;
    }

    // Split the node into the name/purpose part and index part
    $nodeElements = preg_split('/(?=\d)/', $nodeName, 2);

    // If there are more than two elements, something went wrong
    if (count($nodeElements) > 2) {
        return false;
    }

    // If either of the elements is empty, something went wrong
    if (empty($nodeElements[0]) || empty($nodeElements[1])) {
        return false;
    }

    // Return node information packaged as a array
    return [
        'purpose' => $nodeElements[0],
        'index' => $nodeElements[1]
    ];
}
