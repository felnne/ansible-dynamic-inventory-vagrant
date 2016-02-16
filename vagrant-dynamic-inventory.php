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
 * Initialisation - Set up key data-structures
 */

// Hold information about hosts and how to connect to them - used to build host information in the dynamic inventory
$hosts = [];
// Hold information how hosts can be grouped together - used to build group information in the dynamic inventory
$groups = [];
// Hold the contents of the dynamic inventory
$inventory = [];
// Back up of the initial working directory this script was ran in - in case this is needed in the future
$startupWorkingDirectory = getcwd();
// This needs to be path that contains the 'Vagrantfile' to be used as the input for this script
// Typically this script will be in 'provisioning/inventories and the 'Vagrantfile' in the project root
// The default for this variable switches to the project root so the 'Vagrantfile' is accessible
$workingDirectory = '../../';
// DEBUG - Override
$workingDirectory = '/Users/felnne/Projects/WebApps/Web-Applications-Project-Template/';
// When building details on hosts a Fully Qualified Domain Name is built using the hostname from Vagrant and this value
$fqdnDomain = '.v.m';

// Hold any errors or warnings encountered
// Fatal errors will be returned immediately, otherwise they will be returned at the end as comments in the inventory
$warnings = [];

/*
 * Initialisation - Switch to working directory
 */

switch_to_working_directory($workingDirectory);

/*
 * Message input - use Vagrant to generate script input and split this into 'messages' of information
 */
$rawVagrantOutput = get_input_from_vagrant();
$processedVagrantOutput = process_vagrant_output($rawVagrantOutput);

/*
 * Message validation - check each 'message' is valid and from these select only those we are interested in
 * Note: The format of messages is controlled by Vagrant: https://www.vagrantup.com/docs/cli/machine-readable.html
 */
$validMessages = get_valid_messages($processedVagrantOutput);
$specificMessages = get_host_specific_messages($validMessages);
$interestingMessages = get_interesting_messages($specificMessages);

/*
 * Message processing - use the data in each message to build up information on hosts and groups for the inventory
 */

// Build up host information
$hosts = get_hosts_from_messages($interestingMessages);
$hosts = get_host_details_from_messages($hosts, $interestingMessages, $fqdnDomain);

// Build up group information
$groups = make_groups_from_hosts($hosts);

/*
 * Inventory construction - format the relevant information on hosts and groups into an Ansible compatible inventory
 */
$inventory = make_inventory($hosts, $groups, $inventoryName = 'vagrant');

/*
 * Inventory output
 */
echo $inventory;



/*
 * Functions
 */

// Sets the working directory for this script, takes a $workDirectory to switch to
// E.g. switch_to_working_directory('/srv/project/')
//
// TODO: Convert to DocBlock
function switch_to_working_directory($workingDirectory) {
    chdir($workingDirectory);
}

// Calls the Vagrant 'ssh-config' command to get information on machines (hosts) defined, including their name/hostname,
// provider (e.g. VMware) and the private key needed for SSH
// Returns a string containing the 'stdout' output from this command - this will need to be further processed before
// it can be used as input in this script using 'process_vagrant_output()'
//
// E.g. get_input_from_vagrant()
//
// TODO: Convert to DocBlock
function get_input_from_vagrant() {
    return shell_exec('vagrant ssh-config --machine-readable');
}

// Takes raw stdout from Vagrant and converts it into a form that can be used as input for this script
// Returns an array of potential Vagrant messages that can be fed into 'get_valid_messages()'
//
// E.g. process_vagrant_output($rawVagrantOutput)
//
// TODO: Convert to DocBlock
function process_vagrant_output($rawVagrantOutput) {
    // Split Vagrant output into lines
    $arrayOfLines = explode("\n", $rawVagrantOutput);

    // Convert each 'line' from a 'serialised' array of fields, to a multi-dimensional array to make processing easier
    // Not all of these lines are valid messages
    $arrayOfLinesWithFields = array_map(function($line) {
        return explode(',', $line);
    }, $arrayOfLines);

    return $arrayOfLinesWithFields;
}

// Takes an array of $messages, and returns an array of those considered valid
// Any messages which don't validate are discarded, unless $invalidAsWarnings is 'true' to return them as warnings
//
// Messages are considered valid if have 4 or more elements (timestamp, target, type and data) as per the format defined
// by Vagrant - the validity/suitability of the elements within each message elements is checked elsewhere
//
// E.g. get_valid_messages($processedVagrantOutput)
//
// TODO: Convert to DocBlock
function get_valid_messages($messages, $invalidAsWarnings = false) {
    $validMessages = [];

    foreach($messages as $index => $message) {
        if (count($message) >= 4) {
            $validMessages[] = $message;
        } else if ($invalidAsWarnings) {
            $warnings[] = '[WARNING] Message: ' . $index . ' invalid - ' . var_export($message, $return = true);
        }
    }

    return $validMessages;
}

// Takes an array of $messages, and returns an array of those which target a specific machine (host)
// Any messages which aren't specific are discarded, unless $invalidAsWarnings is 'true' to return them as warnings
//
// Where a message is not specific to a host it is either an error, or a more general message, neither of which are of
// use when building the inventory
//
// E.g. get_host_specific_messages($validMessages)
//
// TODO: Convert to DocBlock
function get_host_specific_messages($messages, $nonSpecificAsWarnings = false) {
    $specificMessages = [];

    foreach($messages as $message) {
        // In the Vagrant machine readable format, $message[1] is possibly the host a message belongs to
        if (! empty($message[1])) {
            $specificMessages[] = $message;
        } else if ($nonSpecificAsWarnings) {
            $warnings[] = '[WARNING] Message: ' . $index . ' not specific to a host - ' . var_export($message, $return = true);
        }
    }

    return $specificMessages;
}

// Takes an array of $messages, and returns an array of those that are of a type, and in some cases sub-type, that is
// interesting or useful for building an Ansible inventory
// Any messages which aren't interesting or useful are discarded, unless $nonInterestingAsWarnings is 'true' to return
// them as warnings
//
// Message types are controlled values defined by Vagrant and are therefore predictable, the meaning of the data
// elements(s) in a message are dictated by the message type. E.g. A 'metadata' type message uses 2 data elements.
//
// The message types this function considers interesting are set by '$interestingMessageTypes' and for metadata type
// messages specifically, '$interestingMessageMetadataKeys' sets the metadata keys that are interesting for that type
//
// E.g. get_interesting_messages($specificMessages)
//
// TODO: Convert to DocBlock
function get_interesting_messages($messages, $nonInterestingAsWarnings = false) {
    $interestingMessages = [];
    $interestingMessageTypes = [
        'metadata',
        'ssh-config'
    ];
    $interestingMessageMetadataKeys = [
        'provider'
    ];

    foreach ($messages as $message) {
        // In the Vagrant machine readable format, $message[2] is the type of message
        if (in_array($message[2], $interestingMessageTypes)) {
            // In the Vagrant machine readable format, if the message type is 'metadata', $message[3] is a key
            if ($message[2] == 'metadata' && in_array($message[3], $interestingMessageMetadataKeys)) {
                $interestingMessages[] = $message;
            } else if ($message[2] == 'ssh-config') {
                $interestingMessages[] = $message;
            } else if ($nonInterestingAsWarnings) {
                $warnings[] = '[WARNING] Message: ' . $index . ' has a non-interesting metadata key - ' . var_export($message, $return = true);
            }
        } else if ($nonInterestingAsWarnings) {
            $warnings[] = '[WARNING] Message: ' . $index . ' is a non-interesting message type - ' . var_export($message, $return = true);
        }
    }

    return $interestingMessages;
}

// Returns an array of hosts from the target host an array of $messages refers to
// The hostname is used as the array key and once defined is not duplicated
//
// E.g. get_hosts_from_messages($interestingMessages)
//
// TODO: Convert to DocBlock
function get_hosts_from_messages($messages) {
    $hosts = [];

    foreach ($messages as $message) {
        if (! array_key_exists($message[1], $hosts)) {
            $hosts[$message[1]] = [];
        }
    }

    return $hosts;
}

// Updates an array of $hosts with information filled in from an array of $messages
// Each item of information is decoded and added to a host using a separate function, which this function will call
//
// Some of these functions require additional information as this is not present in the information from messages
// Consequently, these additional arguments will need to be passed to this function:
// * $fqdnDomain - A domain name, needed to add Fully Qualified Domain Names to hosts, otherwise the hostname is used
//
// E.g. get_host_details_from_messages($hosts, $interestingMessages, $fqdnDomain = '.example.com')
//
// TODO: Convert to DocBlock
function get_host_details_from_messages($hosts, $messages, $fqdnDomain = null) {
    foreach ($messages as $message) {
        // For each message select the host it refers to
        $host = $hosts[$message[1]];

        // Each message will only apply to one of these functions - this function does not know which messages belong
        // to which functions deliberately so as to make changing or adding functions as simple as possible
        $host = get_host_provider($host, $message);
        $host = get_host_hostname($host, $message);
        $host = get_host_identity_file($host, $message);

        // These functions set additional information using pre-defined values, or values derived from existing values
        $host = set_host_fqdn($host, $fqdnDomain);

        // Persist the updated host
        $hosts[$message[1]] = $host;
    }

    return $hosts;
}

// Decodes the provider (e.g. 'VMware') for a $host from a suitable $message
//
// E.g. get_host_provider($host, $message)
//
// TODO: Convert to DocBlock
function get_host_provider($host, $message) {
    if ($message[2] != 'metadata' && $message[3] != 'provider') {
        return $host;
    }

    // Record actual value for possible future use
    $host['provider_raw'] = $message[4];

    // Normalise 'provider' value
    if ($message[4] == 'vmware_fusion') {
        $host['provider'] = 'vmware_desktop';
    }

    return $host;
}

// Decodes the hostname for a $host from a suitable $message
//
// E.g. get_host_hostname($host, $message)
//
// TODO: Convert to DocBlock
function get_host_hostname($host, $message) {
    if ($message[2] != 'ssh-config') {
        return $host;
    }

    // For some reason Vagrant gives the SSH connection information as both a string with newlines, and a formatted
    // string. We don't actually care which is used, but we do care that everything ends up being repeated.
    // We therefore ignore the second set of information by removing it.
    $messageValue = explode('FATAL', $message[3])[0];

    // Get the name of the host (e.g. 'foo-dev-node1') which translates to the short hostname (e.g. unqualified)
    $host['hostname'] = strip_string(get_string_between($messageValue, 'Host', 'HostName'));

    return $host;
}

// Decodes the identity file (SSH private key) for a $host from a suitable $message
//
// E.g. get_host_identity_file($host, $message)
//
// TODO: Convert to DocBlock
function get_host_identity_file($host, $message) {
    if ($message[2] != 'ssh-config') {
        return $host;
    }

    // For some reason Vagrant gives the SSH connection information as both a string with newlines, and a formatted
    // string. We don't actually care which is used, but we do care that everything ends up being repeated.
    // We therefore ignore the second set of information by removing it.
    $messageValue = explode('FATAL', $message[3])[0];

    // Get the path to the private key needed to connect to the host, which Vagrant will generate automatically
    $host['identity_file'] = strip_string(get_string_between($messageValue, 'IdentityFile', 'IdentitiesOnly'));

    return $host;
}

// Generates a Fully Qualified Domain Name for a $host from its hostname and a given domain name
//
// E.g. set_host_fqdn($host, '.example.com')
//
// TODO: Convert to DocBlock
function set_host_fqdn($host, $fqdnDomain) {
    if (! array_key_exists('hostname', $host) && empty($host['hostname'])) {
        return $host;
    }

    // Create Fully Qualified Domain Name using the hostname and a common domain namw
    $host['fqdn'] = $host['hostname'] . $fqdnDomain;

    return $host;
}

// Returns an array of $groups based on information from an array of $hosts
// Each group is built using a separate function, which this function will call
//
// Some of these functions require additional information as this is not present in the information from hosts
// Consequently, these additional arguments will need to be passed to this function:
// * $manager - Needed to add groups for the host manager (e.g. Vagrant), defaults to 'vagrant'
//
// E.g. make_groups_from_hosts($hosts, $interestingMessages, $nameForManagerGroup = 'vagrant')
//
// TODO: Convert to DocBlock
function make_groups_from_hosts($hosts, $nameForManagerGroup = 'vagrant') {
    $groups = [];

    $groups = make_manager_group($hosts, $groups, $nameForManagerGroup);
    $groups = make_providers_group($hosts, $groups);
    $groups = make_wsr_1_element_groups($hosts, $groups);

    return $groups;
}

// Creates a group, within an array of $groups, containing all $hosts, for a given $manager (e.g. 'vagrant')
// I.e. all hosts are added to group named after a given manager
// Returns the array of groups passed in, including the additional group created by this function
//
// E.g. make_manager_group($hosts, $groups, $manager = 'vagrant')
//
// TODO: Convert to DocBlock
function make_manager_group($hosts, $groups, $manager) {
    if (! array_key_exists($manager, $groups)) {
        $groups[$manager] = [];
    }

    foreach ($hosts as $host) {
        $groups[$manager][] = $host['fqdn'];
    }

    return $groups;
}

// Creates groups, within an array of $groups, for each host provider for an array of $hosts
// I.e. all hosts using provider A are placed in a group for provider, all hosts using provider B etc.
// Returns the array of groups passed in, including the additional groups created by this function
//
// E.g. make_providers_group($hosts, $groups)
//
// TODO: Convert to DocBlock
function make_providers_group($hosts, $groups) {
    foreach ($hosts as $host) {
        if (array_key_exists('provider', $host)) {
            if (! array_key_exists($host['provider'], $groups)) {
                $groups[$host['provider']] = [];
            }

            $groups[$host['provider']][] = $host['fqdn'];
        }
    }

    return $groups;
}

// Creates groups, within an array of $groups, for each element with a WSR-1 formatted hostname for an array of $hosts
// I.e. if a host has a WSR-1 formatted hostname, groups for the elements in that hostname are created
// Returns the array of groups passed in, including the additional groups created by this function
//
// E.g. make_wsr_1_element_groups($hosts, $groups)
//
// TODO: Convert to DocBlock
function make_wsr_1_element_groups($hosts, $groups) {
    $wsr1Elements = [
        'project',
        'environment',
        'instance',
        'purpose'
    ];

    foreach ($hosts as $hostName => $hostDetails) {
        $hostnameWSRDecoded = decode_wsr_1_hostname($hostName);

        // Skip over hosts without a WSR-1 hostname
        if (! $hostnameWSRDecoded) {
            continue;
        }

        foreach ($wsr1Elements as $element) {
            // Skip over WSR-1 elements that are not defined (usually only applies to 'instance')
            if (empty($hostnameWSRDecoded[$element])) {
                continue;
            }

            if (! array_key_exists($hostnameWSRDecoded[$element], $groups)) {
                $groups[$hostnameWSRDecoded[$element]] = [];
            }
            $groups[$hostnameWSRDecoded[$element]][] = $hostDetails['fqdn'];
        }
    }

    return $groups;
}

// Builds an Ansible formatted inventory for an array of $hosts and $groups named with a specific $inventoryName
// The inventory is built in sections, with each section using a separate function, called by this function
// The inventory is built as an array but returned as a string by imploding the array with a new line character as glue
//
// E.g. make_inventory($hosts, $groups, $inventoryName = 'Vagrant');
//
// TODO: Convert to DocBlock
function make_inventory($hosts, $groups, $inventoryName) {
    $inventory = [];

    // Start with an introduction
    $inventory = array_merge($inventory, make_inventory_introduction($inventoryName));

    // Next add host information
    // (fqdn & identity file)
    $inventory = array_merge($inventory, make_inventory_hosts($hosts));

    // Next add group information
    $inventory = array_merge($inventory, make_inventory_groups($groups));

    // End the inventory with an empty line
    $inventory[] = "\n";

    // Convert the inventory to a new-line joined string
    $inventory = implode("\n", $inventory);

    return $inventory;
}

// Outputs the $inventoryName of an inventory and other general information about dynamic inventories
// This function is designed to be called by 'make_inventory()' and will return a partial inventory array
//
// E.g. make_inventory_introduction($inventoryName = 'Vagrant');
//
// TODO: Convert to DocBlock
function make_inventory_introduction($inventoryName) {
    $inventory = [];

    $inventory[] = '# ' . $inventoryName . ' - Ansible dynamic inventory';
    $inventory[] = '# The contents of this inventory are generated by a script, do not modify manually';

    return $inventory;
}

// Outputs host information for an array of $hosts, including their FQDN (or hostname) and identity file
// This function is designed to be called by 'make_inventory()' and will return a partial inventory array
//
// E.g. make_inventory_hosts($hosts);
//
// TODO: Convert to DocBlock
function make_inventory_hosts($hosts) {
    $inventory = [];

    $inventory[] = "\n";
    $inventory[] = '## Hosts';

    foreach ($hosts as $hostName => $hostDetails) {
        // Prefer a FQDN over a hostname
        if (array_key_exists('fqdn', $hostDetails)) {
            $line = $hostDetails['fqdn'];
        } else {
            $line = $hostName;
        }

        // If an identity file is defined append to the host definition
        if (array_key_exists('identity_file', $hostDetails) && $hostDetails['identity_file'] != '') {
            $line .= " ansible_ssh_private_key_file='" . $hostDetails['identity_file'] . "'";
        }

        $inventory[] = $line;
    }

    return $inventory;
}

// Outputs group information for an array of $groups
// This function is designed to be called by 'make_inventory()' and will return a partial inventory array
//
// E.g. make_inventory_groups($groups);
//
// TODO: Convert to DocBlock
function make_inventory_groups($groups) {
    $inventory = [];

    $inventory[] = "\n";
    $inventory[] = '## Groups';

    foreach($groups as $groupName => $groupMembers) {
        $inventory[] = '[' . $groupName . ']';

        // Merge in members (hosts or group names) of group - empty or falsy values are omitted
        $inventory = array_merge($inventory, array_filter($groupMembers));

        // Add separator after each group
        $inventory[] = '';
    }

    // Remove trailing separator
    array_pop($inventory);

    return $inventory;
}

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
// If any element is not defined, a null value will be set for that element
//
// E.g. decode_wsr_1_hostname('pristine-wonderment-of-the-ages-dev-felnne-db1') returns:
// (Array - [Key] = value)
//  [project]     = pristine-wonderment-of-the-ages
//  [environment] = dev
//  [instance]    = felnne
//  [node]        = db1
//  [purpose]     = db
//  [index]       = 1
//
// TODO: Convert to DocBlock
// TODO: Refactor this
function decode_wsr_1_hostname($hostname) {
    $project = null;
    $environment = null;
    $instance = null;
    $node = null;
    $purpose = null;
    $index = null;

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

    // Further decode the node type
    $nodeDecoded = decode_wsr_1_node_type($node);
    if ($nodeDecoded !== false && is_array($nodeDecoded)) {
        if (array_key_exists('purpose', $nodeDecoded) && ! empty($nodeDecoded['purpose'])) {
            $purpose = $nodeDecoded['purpose'];
        }
        if (array_key_exists('index', $nodeDecoded) && ! empty($nodeDecoded['index'])) {
            $index = $nodeDecoded['index'];
        }
    }

    // We can now package up the WSR elements and return them as an array
    $output = [
        'project' => $project,
        'environment' => $environment,
        'instance' => $instance,
        'node' => $node,
        'purpose' => $purpose,
        'index' => $index
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
function decode_wsr_1_node_type($nodeName) {
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
