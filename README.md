A rewrite of GlobalBlocking using MediaWiki's new user block abstraction with support for registered user blocks. 

### Functionality
Provides Special:GlobalBlock, Special:GlobalUnblock, Special:GlobalBlockList.

All changes to global blocks (that is: creation, modification, removal) are logged on the wiki the action has been performed on.
Global blocks (and all changes made to them) are instantly visible at Special:GlobalBlockList regardless of where actions are
performed.

If central job queue can be written from other wikis, all global block actions will also be logged on the central wiki if
performed elsewhere.

### Configuration
| **Variable**         | **Required or default** | **Description**                                                 |
|----------------------|-------------------------|-----------------------------------------------------------------|
| `$wgGUBCentralWiki`  | yes                     | Wiki ID (database name) of the central wiki to store blocks in. |
| `$wgGUBApplyBlocks`  | `true`                  | If true, global blocks will have an effect.                     |

### User rights
| **Right**            | **Description**                                                                           |
|----------------------|-------------------------------------------------------------------------------------------|
| `globalblock`        | Grants access to Special:GlobalBlock, Special:GlobalUnblock.                              |
| `globalblockexempt`  | Users with this right will bypass global blocks.                                          |

Both rights are granted to the bureaucrat group by default.
