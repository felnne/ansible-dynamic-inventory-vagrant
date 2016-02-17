# Vagrant Ansible dynamic inventory

This script is designed to act as an Ansible dynamic inventory, each time it is run a instantaneous representation
of the current Vagrant environment is returned.

**Note:** This script is a proof-of-concept! Please submit feedback if you encounter any errors or have suggestions.

This script is designed for use in BAS projects, but should be useful generally, though some features will not work.

## Features

* Represents the current Vagrant environment as an Ansible inventory using hosts and groups
* For hosts, automatically generates Fully Qualified Domain Names if desired
* For hosts, automatically includes the required identity file to connect to a host using SSH
* For groups, automatically creates groups for the Manager (i.e. Vagrant) and the Provider (i.e. Virtualbox)
* For groups, if using compatible hostname's for each host, creates groups based on the Project, Environment and Purpose

The groups based on the hostname, are designed for projects that follow BAS conventions for hostname's. Following this
convention is optional, but if not followed these groups cannot be created. See the *WSR-1 hostnames* section for 
more information.

Example output:

```
# vagrant - Ansible dynamic inventory
# The contents of this inventory are generated by a script, do not modify manually


## Hosts
pristine-dev-node1.v.m ansible_ssh_private_key_file='/Users/felnne/Projects/WebApps/Web-Applications-Project-Template/.vagrant/machines/pristine-dev-node1/vmware_fusion/private_key'
pristine-dev-node2


## Groups
[vagrant]
pristine-dev-node1.v.m

[vmware_desktop]
pristine-dev-node1.v.m

[pristine]
pristine-dev-node1.v.m

[dev]
pristine-dev-node1.v.m

[node]
pristine-dev-node1.v.m
```

## Usage

Place `vagrant-dynamic-inventory.php` in a suitable location in your project, e.g. `provisioning/inventories`.

Make sure to grant execute permissions to the script:

```
$ chmod +x provisioning/inventories/vagrant-dynamic-inventory.php
```

Configure Ansible to use this inventory, either using a project `ansible.cfg` file:

```ini
[defaults]
inventory = provisioning/inventories/vagrant-dynamic-inventory.php
```

Or pas the location on the command line:

```
$ ansible-playbook -i provisioning/inventories/vagrant-dynamic-inventory.php playbook.yml
```

Alternatively you can use a directory of inventories, useful if you want to define your own groups or in environments
with multiple dynamic inventories:

```ini
[defaults]
inventory = provisioning/inventories/
```

Or:

```
$ ansible-playbook -i provisioning/inventories/ playbook.yml
```

**Note:** Ansible will try to use any file in the directory you specify as an inventory.

Ansible has more general information on [Ansible inventories](http://docs.ansible.com/ansible/intro_inventory.html) 
and [dynamic inventories](http://docs.ansible.com/ansible/intro_dynamic_inventory.html) if needed.

Now each time Ansible is run, the dynamic inventory script will be called by Ansible. This will get information from
Vagrant and provide this to Ansible. No inventory file will be saved by this script, though you can do this for testing
purposes if desired.

**Note:** It is assumed you have already have a Vagrant environment and that its machines are running.

To see what this script produces you can run it directly:

```
$ php provisioning/inventories/vagrant-dynamic-inventory.php
```

### WSR-1 hostnames

TODO.

## Configuration

TODO.

## Developing

### Contributing policy

This project welcomes contributions, see `CONTRIBUTING.md` for our general policy.

## Licence

Copyright 2016 NERC BAS.

Unless stated otherwise, all documentation is licensed under the Open Government License - version 3. All code is
licensed under the MIT license.

Copies of these licenses are included within this project.
