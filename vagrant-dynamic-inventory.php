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
 * Setup the data-structures that will hold information about hosts and how to connect to them
 * This is used to build the dynamic inventory for Ansible
 */
$hosts = [];

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
 * Host information is now ready to be formatted as an Ansible inventory file
 */

// DEBUG output
var_dump($hosts);


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
