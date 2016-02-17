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

/**
 * Sets the working directory for this script
 *
 * @param string $workingDirectory The working directory to switch to
 * @return void
 *
 * @example switch_to_working_directory('/srv/project/');
 */
function switch_to_working_directory($workingDirectory) {
    chdir($workingDirectory);
}

/**
 * Calls Vagrant to discover information about running machines (hosts)
 *
 * Calls the Vagrant 'ssh-config' command to get information on machines (hosts) defined, including their name/hostname,
 * provider (e.g. VMware) and the private key needed for SSH.
 *
 * @see process_vagrant_output() For how output from this function can be prepared as input into this script
 *
 * @return string Contains the 'stdout' from Vagrant, which consists of a number of potential messages
 *
 * @example get_input_from_vagrant();
 */
function get_input_from_vagrant() {
    return shell_exec('vagrant ssh-config --machine-readable');
}

/**
 * Takes stdout from Vagrant and converts into potential messages
 *
 * @see get_input_from_vagrant() For getting Stdout from a Vagrant command
 * @see get_valid_messages() For validating which potential messages are suitable for further processing
 *
 * @param string $rawVagrantOutput Stdout from Vagrant
 * @return array An array of potential messages
 *
 * @example process_vagrant_output($rawVagrantOutput);
 */
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

/**
 * For a set of Vagrant messages, returns only those considered valid
 *
 * Messages are considered valid if have 4 or more elements (timestamp, target, type and data) as per the format defined
 * by Vagrant - the suitability of the elements within each message elements is checked elsewhere.
 *
 * @see https://www.vagrantup.com/docs/cli/machine-readable.html For information on the format of  Vagrant 'messages'
 * @see get_host_specific_messages() For determining which valid messages are suitable for further processing
 *
 * @param array $messages A set of potential Vagrant messages to be validated
 * @param bool $invalidAsWarnings If 'true', return invalid messages as warnings, otherwise they are discarded
 * @return array A set of valid messages
 *
 * @example get_valid_messages($processedVagrantOutput);
 */
function get_valid_messages(array $messages, $invalidAsWarnings = false) {
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

/**
 * For a set of Vagrant messages, returns only those which address a specific machine (host)
 *
 * A message is said to be non-specific when it does not refer to a specific machine (host), these include errors or a
 * more general message.
 *
 * @see get_valid_messages() For determining the validity of messages
 * @see get_interesting_messages() For determining which host specific messages are suitable for further processing
 *
 * @param array $messages A set of previously validated messages
 * @param bool $nonSpecificAsWarnings If 'true', return non-specific messages as warnings, otherwise they are discarded
 * @return array A set of messages which specify a specific host
 *
 * @example get_host_specific_messages($specificMessages);
 */
function get_host_specific_messages(array $messages, $nonSpecificAsWarnings = false) {
    $specificMessages = [];

    foreach($messages as $index => $message) {
        // In the Vagrant machine readable format, $message[1] is possibly the host a message belongs to
        if (! empty($message[1])) {
            $specificMessages[] = $message;
        } else if ($nonSpecificAsWarnings) {
            $warnings[] = '[WARNING] Message: ' . $index . ' not specific to a host - ' . var_export($message, $return = true);
        }
    }

    return $specificMessages;
}

/**
 * For a set of Vagrant messages, returns those only useful for building an inventory
 *
 * A message is said to be useful or interesting in this context if it contains information about the provider used for
 * a host (e.g. VMware) or if it contains SSH configuration information such as the identity file to connect to a host.
 *
 * Vagrant uses a 'type' field with a set of controlled values allowing the type of message to be checked easily. Some
 * message types use different formats for the remaining 'data' field(s) of a message, which can naturally be inferred
 * from the message type.
 *
 * The types of message this function considers interesting are defined by '$interestingMessageTypes'. For 'metadata'
 * type messages an additional '$interestingMessageMetadataKeys' variable is used for additional filtering.
 *
 * @see get_host_specific_messages() For determining which messages are specific to a host
 * @see get_hosts_from_messages() For gathering any hosts specified in a set of messages
 * @see get_host_details_from_messages() For gathering details about hosts specified in a set of messages
 *
 * @param array $messages A set of messages, ideally known to be specific to a host
 * @param bool $nonInterestingAsWarnings If 'true', return non-interesting messages as warnings, otherwise they are discarded
 * @return array A set of messages which are interesting for building an inventory
 *
 * @example get_interesting_messages($specificMessages);
 */
function get_interesting_messages(array $messages, $nonInterestingAsWarnings = false) {
    $interestingMessages = [];
    $interestingMessageTypes = [
        'metadata',
        'ssh-config'
    ];
    $interestingMessageMetadataKeys = [
        'provider'
    ];

    foreach ($messages as $index => $message) {
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

/**
 * For a set of Vagrant messages, returns a list of any of uniquely specified hosts
 *
 * Most vagrant messages are specific to a specific machine (host), for a set of messages this function will return an
 * array of unique hosts. The name of each host will be used as a named index in the returned array, the value of each
 * index is an empty array, designed to be populated with other information about each host.
 *
 * @see get_interesting_messages() For gathering a list of messages relatent to building an inventory
 * @see get_host_details_from_messages() For populating a set of hosts from this function with additional information
 *
 * @param array $messages A set of messages which specify a host
 * @return array An array with named index for each unique host, an empty array is set as the value for each index
 *
 * @example get_hosts_from_messages($interestingMessages);
 */
function get_hosts_from_messages(array $messages) {
    $hosts = [];

    foreach ($messages as $message) {
        if (! array_key_exists($message[1], $hosts)) {
            $hosts[$message[1]] = [];
        }
    }

    return $hosts;
}

/**
 * Updates a set of hosts with information form a set of Vagrant messages
 *
 * The array of hosts passed to this function must use a host names as indexes and empty arrays as values.
 * Each message is passed through a number of functions which can decode a specific piece of information for a specific
 * kind of message. If the message is the right kind for a function it will update the relevant array for the host the
 * message relates to.
 *
 * Additional functions create new information about a host, based on information gathered from messages combined with
 * arguments to this function.
 *
 * @see get_hosts_from_messages() For gathering a list of hosts from a list of messages, formatted for this function
 * @see get_interesting_messages() For gathering a list of messages relatent to building an inventory
 * @see get_host_provider() For determining the provider for a host from a relevant Vagrant message
 * @see get_host_hostname() For determining the hostname for a host from a relevant Vagrant message
 * @see get_host_identity_file() For determining the identity file to connect to a host from a relevant Vagrant message
 * @see set_host_fqdn() For constructing a Fully Qualified Domain Name from existing host information and a domain name
 * @see make_groups_from_hosts() For creating groups based on characteristics about hosts and how they are managed
 * @see make_inventory() For outputting host information in the Ansible inventory format
 *
 * @param array $hosts
 * @param array $messages
 * @param string $fqdnDomain
 * @return array
 *
 * @example get_host_details_from_messages($hosts, $interestingMessages, $fqdnDomain = 'example.com');
 */
function get_host_details_from_messages(array $hosts, array $messages, $fqdnDomain = null) {
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

/**
 * Determines the provider for a host from a relevant Vagrant message
 *
 * The provider is the underlying platform or application that provides a host. Typically in the case of Vagrant, this
 * is a hypervisor such as VMware or Virtualbox. Vagrant provides this information in a 'metadata' type message.
 *
 * Messages which don't refer to this specific piece of information will be ignored by this function.
 *
 * @see get_host_details_from_messages() For building up information about hosts using these sort of functions
 *
 * @param array $host The host the decoded provider refers to
 * @param array $message A Vagrant message, ideally a metadata message of the relevant type
 * @return array Where a provider is present in the message, an updated host, otherwise an unmodified host
 *
 * @example get_host_provider($host, $message);
 */
function get_host_provider(array $host, array $message) {
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

/**
 * Determines the hostname for a host from a relevant Vagrant message
 *
 * The hostname is typically the same as the machine name in a Vagrantfile, but can be different. For an inventory the
 * hostname is the required name in order for Ansible to connect to the host using SSH.  Vagrant provides this
 * information in a 'ssh-config' type message.
 *
 * Messages which don't refer to this specific piece of information will be ignored by this function.
 *
 * @see get_host_details_from_messages() For building up information about hosts using these sort of functions
 *
 * @param array $host The host the decoded provider refers to
 * @param array $message A Vagrant message, ideally a ssh-config message
 * @return array Where a hostname is present in the message, an updated host, otherwise an unmodified host
 *
 * @example get_host_hostname($host, $message);
 */
function get_host_hostname(array $host, array $message) {
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

/**
 * Determines the identity file for a host from a relevant Vagrant message
 *
 * The identity file is typically a randomly generated private key created by Vagrant when the host is first created.
 * For an inventory the identity file is required in order for Ansible to connect to the host using SSH. Vagrant
 * provides this information in a 'ssh-config' type message.
 *
 * Messages which don't refer to this specific piece of information will be ignored by this function.
 *
 * @see get_host_details_from_messages() For building up information about hosts using these sort of functions
 *
 * @param array $host The host the decoded provider refers to
 * @param array $message A Vagrant message, ideally a ssh-config message
 * @return array Where an identity file is present in the message, an updated host, otherwise an unmodified host
 *
 * @example get_host_identity_file($host, $message);
 */
function get_host_identity_file(array $host, array $message) {
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

/**
 * Constructs a Fully Qualified Domain Name (FQDN) for a host using a hostname and given domain name
 *
 * A FQDN is essentially a hostname followed by a domain name (e.g. hostname.domain.tld), anything after the first
 * full stop, '.', is considered the domain name, anything before is considered the hostname.
 *
 * The domain name used can be any valid domain name, however it is strongly recommended to use a domain name you
 * control, or that is reserved for internal/test purposes as per RFC 2606.
 *
 * If the hostname is not known for a host, the host is returned unmodified.
 *
 * @see get_host_details_from_messages() For building up information about hosts using these sort of functions
 * @see get_host_hostname() For specifically determining the hostname for a host from a relevant Vagrant message
 * @see https://tools.ietf.org/html/rfc2606 For a list of domain names and TLDs reserved for testing
 *
 * @param array $host The host the FQDN will apply to and which ideally contains a 'hostname' property
 * @param string $fqdnDomain The domain name or TLD for use in constructing the FQDN
 * @return array Where a host has a hostname, an updated host, otherwise an unmodified host
 *
 * @example set_host_fqdn($host, $fqdnDomain = 'example.com');
 */
function set_host_fqdn(array $host, $fqdnDomain) {
    if (! array_key_exists('hostname', $host) && empty($host['hostname'])) {
        return $host;
    }

    // Create Fully Qualified Domain Name using the hostname and a common domain name
    $host['fqdn'] = $host['hostname'] . $fqdnDomain;

    return $host;
}

/**
 * Creates a series of groups for a set of hosts based on their characteristics and how they are managed
 *
 * Most of these groups are derived from information known about hosts (such as its provider). Other groups can be made
 * using other criteria, for example grouping all hosts managed using Vagrant.
 *
 * Each group consists of a named index in an array, the value of each index is an array of host FQDNs.
 * These groups are directly comparable to Ansible inventory groups and are used for this purpose.
 *
 * To build each group, hosts and other information, are passed to a number of functions, each responsible for creating
 * a specific group or series of groups (provider groups for example).
 *
 * Note: The term 'group' is used to represent a grouping of hosts in an abstract way (but implemented using an array
 * of named arrays), and in specifically in terms of Ansible inventory groups.
 *
 * @see get_hosts_from_messages() For gathering a list of hosts from a list of messages
 * @see get_host_details_from_messages() For populating a set of hosts from with information from a list of messages
 * @see make_manager_group() For creating groups based on the manager used for a host (e.g. Vagrant)
 * @see make_providers_group() For creating groups based on the provider used for a host (e.g. VMware)
 * @see make_wsr_1_element_groups() For creating groups based on hostname's formatted according to WSR-1
 * @see make_inventory() For outputting group information in the Ansible inventory format
 *
 * @param array $hosts The set of hosts to be grouped
 * @param string $nameForManagerGroup The name of the manager (e.g. vagrant) for a group based on how hosts are managed
 * @return array The set of groups created
 *
 * @example make_groups_from_hosts($hosts);
 */
function make_groups_from_hosts(array $hosts, $nameForManagerGroup = 'vagrant') {
    $groups = [];

    $groups = make_manager_group($hosts, $groups, $nameForManagerGroup);
    $groups = make_providers_group($hosts, $groups);
    $groups = make_wsr_1_element_groups($hosts, $groups);

    return $groups;
}

/**
 * Creates groups for a set of hosts based on how they are managed
 *
 * Each host uses a 'manager', which is usually Vagrant, this group will contain all hosts.
 *
 * This group is useful in environments where this inventory is one of many, and may therefore contain multiple
 * managers, each with their own group containing hosts they manage.
 *
 * Note: Assumptions may be made as to the name of these management groups by other scripts of provisioning systems.
 * It is therefore strongly advised to use any defaults offered for these groups.
 *
 * @see make_groups_from_hosts() For creating a series of groups for hosts using these sort of functions
 *
 * @param array $hosts The set of hosts to be grouped
 * @param array $groups The set of groups this function will append any new groups to
 * @param string $manager The name of the manager for the given hosts
 * @return array If new groups were added, an updated set of groups, otherwise an unmodified set of groups
 *
 * @example make_manager_group($hosts, $groups, $manager = 'vagrant');
 */
function make_manager_group(array $hosts, array $groups, $manager) {
    if (! array_key_exists($manager, $groups)) {
        $groups[$manager] = [];
    }

    foreach ($hosts as $host) {
        $groups[$manager][] = $host['fqdn'];
    }

    return $groups;
}

/**
 * Creates groups for a set of hosts based on their provider
 *
 * Each host uses a 'provider', the underlying platform or application that provides a host. Typically in the case of
 * Vagrant, this is a hypervisor such as VMware or Virtualbox.
 *
 * These groups are useful in environments where this inventory is one of many, and may therefore contain multiple
 * providers (not typically local providers, but a mixture of local and remote providers for example), each with their
 * own group containing hosts they 'provide for'.
 *
 * @param array $hosts The set of hosts to be grouped
 * @param array $groups The set of groups this function will append any new groups to
 * @return array If new groups were added, an updated set of groups, otherwise an unmodified set of groups
 *
 * @example make_providers_group($hosts, $groups);
 */
function make_providers_group(array $hosts, array $groups) {
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

/**
 * Creates groups for a set of hosts based on elements from WSR-1 formatted hostname's
 *
 * If a host uses a WSR-1 formatted hostname (such as 'foo-dev-web1'), groups can be made for the various elements
 * that make up this hostname, such as Project ('foo'), Environment ('dev') and Purpose ('web').
 *
 * Where hosts, or a specific host, don't use such hostname's they are safely ignored by this function.
 *
 * These groups are useful in both environments with just this inventory, and as part of environments with multiple
 * inventories, targeting different WSR-1 Environments for example (e.g. Development using Vagrant, Production using
 * another manager/inventory).
 *
 * @param array $hosts The set of hosts to be grouped
 * @param array $groups The set of groups this function will append any new groups to
 * @return array If new groups were added, an updated set of groups, otherwise an unmodified set of groups
 *
 * @example make_wsr_1_element_groups($hosts, $groups);
 */
function make_wsr_1_element_groups(array $hosts, array $groups) {
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

/**
 * Creates an Ansible formatted inventory from a set of hosts and groups with a given name
 *
 * This function expects hosts to be structured as an array of named indexes containing an array of properties about
 * each host (e.g. its Full Qualified Domain Name).
 *
 * This function expects groups to be structured as an array of named groups containing an array of hosts to be members
 * of each group.
 *
 * This function is generic and does not assume an inventory is being generated for any specific purpose (e.g. this is
 * not a function specific to building a Vagrant inventory). Therefore a descriptive name is required to identify the
 * generated inventory.
 *
 * To build the inventory, hosts, groups and other information, are passed to a number of functions, each responsible
 * for creating sections of the inventory (host definitions for example).
 *
 * @see get_host_details_from_messages() For building up information about hosts
 * @see make_groups_from_hosts() For building up information about groups of hosts
 * @see make_inventory_introduction() Outputs introductory comments for an inventory
 * @see make_inventory_hosts() Outputs hosts definitions for a set of hosts
 * @see make_inventory_groups() Outputs group definitions for a set of groups
 *
 * @param array $hosts A set of hosts, each containing properties about each host
 * @param array $groups A set of groups
 * @param string $inventoryName A descriptive name for this inventory, displayed in comments in the inventory
 * @return string A constructed inventory file which can be safely echoed
 *
 * @example make_inventory($hosts, $groups, $inventoryName = 'Vagrant');
 */
function make_inventory(array $hosts, array $groups, $inventoryName) {
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

/**
 * Makes introductory lines for the inventory as comments
 *
 * This is mostly a formality, stating the output is dynamic and should not be modified, and stating which inventory
 * it is. Since these dynamic inventories are designed to be consumed by Ansible directly only minimal information is
 * outputted. It is mainly intended for users when running the dynamic inventory from the command line for debugging.
 *
 * @see make_inventory() For building up an inventory using these sort of functions
 *
 * @param string $inventoryName The descriptive name for the inventory
 * @return array A set of lines to be added to the inventory
 *
 * @example make_inventory_introduction($inventoryName = 'Vagrant');
 */
function make_inventory_introduction($inventoryName) {
    $inventory = [];

    $inventory[] = '# ' . $inventoryName . ' - Ansible dynamic inventory';
    $inventory[] = '# The contents of this inventory are generated by a script, do not modify manually';

    return $inventory;
}

/**
 * Makes host definition lines for the inventory for a set of hosts
 *
 * These definitions consist of the hostname and the identity file Ansible requires to connect to a host. The hostname
 * is either the FQDN (if specified, preferred), the hostname (if specified) or the name of the host given by Vagrant.
 *
 * @see make_inventory() For building up an inventory using these sort of functions
 *
 * @param array $hosts A set of hosts to be defined within the inventory
 * @return array A set of lines to be added to the inventory
 *
 * @example make_inventory_hosts($hosts);
 */
function make_inventory_hosts(array $hosts) {
    $inventory = [];

    $inventory[] = "\n";
    $inventory[] = '## Hosts';

    foreach ($hosts as $hostName => $hostDetails) {
        // Prefer a FQDN over a hostname and fall back to the machine name
        if (array_key_exists('fqdn', $hostDetails)) {
            $line = $hostDetails['fqdn'];
        } else if (array_key_exists('hostname', $hostDetails)) {
            $line = $hostDetails['hostname'];
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

/**
 * Makes group definition lines for the inventory for a set of groups
 *
 * These definitions consist of the name of the group, followed by the members of that group. Each group is separated
 * with a new line character to improve readability.
 *
 * @see make_inventory() For building up an inventory using these sort of functions
 *
 * @param array $groups A set of groups to be defined within the inventory
 * @return array A set of lines to be added to the inventory
 *
 * @example make_inventory_groups($groups);
 */
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

/**
 * Gets the substring between, and not including, a start and end string
 *
 * @param string $string The complete string, which contains the start/end strings and desired sub-string in between
 * @param string $start The string that defines the start of the desired substring, but is not part of the substring
 * @param string $end The string that defines the end of the desired substring, but is not part of the substring
 * @return string The desired substring, or an empty string if the start string is not found in the complete string
 *
 * @example get_string_between('Foo Bar Baz', 'Foo', 'Baz');  // returns 'Bar'
 */
function get_string_between($string, $start, $end){
    $string = ' ' . $string;

    $ini = strpos($string, $start);

    if ($ini == 0) {
        return '';
    }

    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;

    return substr($string, $ini, $len);
}

/**
 * Removes leading/trailing spaces and optionally, single/double quotes and newlines from a string
 *
 * @param string $string The string to be 'stripped'
 * @param bool $strip_quotes If 'true' single or double quotes will be removed, 'true' by default
 * @param bool $strip_newlines If 'true' new line characters will be removed, 'true' by default
 * @return string The 'stripped' string
 *
 * @example trim_string(' "foo" \n bar '); // returns 'foo bar'
 */
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

/**
 * Attempts to decode a WSR-1 formatted hostname return its separate elements
 *
 * A WSR-1 formatted hostname such as 'pristine-wonderment-of-the-ages-dev-felnne-db1' will be split into its elements:
 * - Project     'pristine-wonderment-of-the-ages'
 * - Environment 'dev'
 * - Instance    'felnne'
 * - node        'db1'
 * - purpose     'db'
 * - index       '1'
 *
 * Non-optional elements that can't be found in a hostname will cause this function to fail and return an error.
 * Optional elements that can't be found in a hostname will be returned as 'null' values.
 * Hostname's which are not found to be WSR-1 compliant will cause this function to fail and return an error.
 *
 * @see decode_wsr_1_node_type() For how to decode the Node element into its sub-elements
 *
 * @param string $hostname The hostname to decode
 * @return array|bool The elements of the hostname which could be decoded, or 'false' if an error occurred
 *
 * @example decode_wsr_1_hostname($hostname = 'pristine-wonderment-of-the-ages-dev-felnne-db1');
 */
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

/**
 * Attempts to decode a node element from a WSR-1 formatted hostname and return its separate sub-elements
 *
 * A WSR-1 formatted hostname contains a 'Node' element, this is made up of a Purpose and an Index sub-element.
 * A node such as 'db1' will be split into its sub-elements:
 * - Purpose 'db'
 * - Index   '1'
 *
 * @see decode_wsr_1_hostname() For how to decode a WSR-1 hostname into its elements for this function
 *
 * @param string $nodeName The Node to decode
 * @return array|bool The sub-elements of the Node, or 'false' if an error occurred
 *
 * @example decode_wsr_1_node_type($node);
 */
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
