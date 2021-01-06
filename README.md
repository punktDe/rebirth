# Rebirth your orphaned nodes

This package can help you to move orphaned node in the Neos Content Repository.

This package is based on the ideas and code of [Ttree.Rebirth](https://github.com/ttreeagency/Rebirth).

*Warning*: Working with broken / orphaned nodes can be hard, please backup your data before using this package.

_Currently this package supports only document nodes restoration._ That means you can only restore documents
including their content (and further children), but not only content that has been orphaned.

How to use ?
------------

- Optional: For each site where you want to restore orphaned nodes, you can create a "RestoreTarget" node. This can be
  used as the automatically detected target for document node restoration.
- Go to the CLI and run the restore command.

CLI commands
------------

## List the current orphaned document nodes

    flow rebirth:list

## List the current orphaned nodes for a specific type

    flow rebirth:list --type Neos.Neos:Document
    
## Restore everything under the "Restore" node of the current site

    flow rebirth:restoreAll

*Note*: if you have no RestoreTarget node, this will fail with the message *Missing restoration target for the currentnode*. Either create a RestoreTarget node, use the flag `--auto-create-target=1` to create one when needed, or specify the target node using `--target`.

## Restore everything under the "Restore" node of the current site, only for the given type

    flow rebirth:restoreAll --type Neos.Neos:Document
    
## Restore everything under the given target node

    flow rebirth:restoreAll --target f34f834b-c36b-43eb-a580-f0e2f168b241

## Prune all orphaned document nodes

    flow rebirth:pruneAll

## Prune all orphaned document nodes for a specific type

    flow rebirth:pruneAll --type Neos.Neos:Document

Troubleshooting
---------------

If you get the message *Missing restoration target for the current node* even though a RestoreTarget node has been
created, make sure you have a RestoreTarget node matching the context of the nodes to be restored. Specifically check
the content dimensions. You must have a RestoreTarget node that can be found in the same dimension value combination
as the node to be restored.