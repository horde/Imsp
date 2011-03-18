<?php
require_once 'Net/IMSP.php';
/**
 * Horde_Imsp_Utils::
 *
 * Copyright 2003-2011 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Michael Rubinsky <mrubinsk@horde.org>
 * @package Horde_Imsp
 */
class Horde_Imsp_Utils {

    /**
     * Utility function to retrieve the names of all the address books
     * that the user has access to, along with the acl for those
     * books.  For information about the $serverInfo array see
     * turba/config/sources.php as this is the cfgSources[] entry for
     * the address books.
     *
     * @param array $serverInfo  Information about the server
     *                           and the current user.
     *
     * @return array  Information about all the address books or PEAR_Error.
     */
    function getAllBooks($serverInfo)
    {
        $foundDefault = false;
        $results = array();
        $imsp = &Horde_Imsp::singleton('Book', $serverInfo['params']);
        $result = $imsp->init();

        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }
        $books = $imsp->getAddressBookList();
        if (is_a($books, 'PEAR_Error')) {
            return $books;
        }
        $bCount = count($books);
        for ($i = 0; $i < $bCount; $i++) {
            $newBook = $serverInfo;
            if ($books[$i] != $serverInfo['params']['username']) {
                $newBook['title'] = $books[$i];
                // We need the 'name' param to store the IMSP
                // address book name when not using shares (for BC).
                $newBook['params']['name'] = $books[$i];
                $newBook['params']['is_root'] = false;
                $newBook['params']['my_rights'] = $imsp->myRights($books[$i]);
            } else {
                $foundDefault = true;
                $newBook['params']['my_rights'] = $imsp->myRights($books[$i]);
            }
            $results[] = $newBook;
        }

        /* If there is no default address book (named username) then we should create one. */
        if (!$foundDefault) {
            $result = $imsp->createAddressBook($serverInfo['params']['username']);
            if (is_a($result, 'PEAR_Error')) {
                return PEAR::raiseError('Login to IMSP host failed.' .
                                        ': Default address book is missing and could not be created.');
            }
        }
        return $results;
    }

    /**
     * Utility function to make it easier for client applications to delete
     * address books without having to create imsp drivers.  The $source array
     * is a horde/turba style $cfgSources entry for the address book being
     * deleted.
     *
     * @param array $source  Information about the address book being deleted.
     *
     * @return mixed  True on success or PEAR_Error on failure.
     */
    function deleteBook($source)
    {
        if (is_array($source)) {
            // Not using shares
            $params = $source['params'];
            $bookName = $source['title'];
        } else {
            // Using shares.
            $params = $GLOBALS['cfgSources']['imsp:' . $source]['params'];
            $bookName = $source;
        }
        $imsp = &Horde_Imsp::singleton('Book', $params);
        $result = $imsp->init();
        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }

        $result = $imsp->deleteAddressBook($bookName);
        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }
        return true;
    }

    /**
     * Utility function to help clients create new address books without having
     * to create an imsp driver instance first.
     *
     * @param array $source    Information about the user's default IMSP
     *                         address book.
     * @param string $newName  The name of the new address book.
     *
     * @return mixed  true on success or PEAR_Error on failure.
     */
    function createBook($source, $newName)
    {
        $imsp = &Horde_Imsp::singleton('Book', $source['params']);
        $result = $imsp->init();
        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }

        // We now check if the username is already prepended to
        // the address book name or not.
        if (strpos($newName, $source['params']['username'] . '.') === 0) {
            $name = $newName;
        } else {
            $name = $source['params']['username'] . '.' . $newName;
        }
        $result = $imsp->createAddressBook($name);
        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }
        return true;
    }

    /**
     * Synchronize Horde_Shares to existing IMSP address books.
     *
     * @param Horde_Share $share_obj  The Horde_Share object to use.
     * @param array $serverInfo       Information about the IMSP server and
     *                                the current user.
     *
     * @return mixed  Array describing any shares added or removed  | PEAR_Error.
     */
    function synchShares($share_obj, $serverInfo)
    {
        $found_shares = array();
        $return = array('added' => array(), 'removed' => array());
        $params = array();

        $imsp = &Horde_Imsp::singleton('Book', $serverInfo['params']);
        $result = $imsp->init();
        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }
        $abooks = $imsp->getAddressBookList();
        if (is_a($abooks, 'PEAR_Error')) {
            return $abooks;
        }

        // Do we have a default address book? If not, create one.
        if (array_search($serverInfo['params']['username'], $abooks) === false) {
            $result = $imsp->createAddressbook($serverInfo['params']['username']);
            if (!is_a($result, 'PEAR_Error')) {
                // Make sure we add it to our list of books.
                $abooks[] = $serverInfo['params']['username'];
            }
        }

        $shares = $share_obj->listShares($GLOBALS['registry']->getAuth());
        // A share for each IMSP adress book we can see.
        foreach ($abooks as $abook_uid) {
            $found = false;
            foreach ($shares as $id => $share) {
                $params = @unserialize($share->get('params'));
                if (!empty($params['name']) && $params['name'] == $abook_uid &&
                    $params['source'] == 'imsp') {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $shareparams = array('name' => $abook_uid,
                                     'source' => 'imsp');

                $params['uid'] = md5(mt_rand());
                $params['name'] = $abook_uid . ' (IMSP)';
                $params['acl'] = $imsp->myRights($abook_uid);
                if ($abook_uid == $serverInfo['params']['username']) {
                    // This is the default address book
                    $shareparams['default'] = true;
                } else {
                    $shareparams['default'] = false;
                }
                if (Horde_Imsp_Utils::_isOwner($abook_uid,
                                             $serverInfo['params']['username'],
                                             $params['acl'])) {
                    $params['owner'] = $GLOBALS['registry']->getAuth();
                } else {
                    // TODO: What to do for the owner when it's not current user?
                    //       We'd have to try to match the owner per IMSP
                    //       address book name to a Horde user...how to do that
                    //       without assuming usernames are equal?
                }
                $result = Horde_Imsp_Utils::_createShare($share_obj, $params, $shareparams);
                if (is_a($result, 'PEAR_Error')) {
                    return $result;
                }
                $return['added'][] = $params['uid'];
            } else {
                // Share already exists, just update the acl.
                $params['acl'] = $imsp->myRights($abook_uid);
            }
            $found_shares[] = $abook_uid;
        }

        // Now prune any shares that no longer exist on the IMSP server.
        $existing = $share_obj->listShares($GLOBALS['registry']->getAuth(), array('perm' => Horde_Perms::READ));
        foreach ($existing as $key => $share) {
            $temp = unserialize($share->get('params'));
            if (is_array($temp)) {
                $sourceType = $temp['source'];
                if ($sourceType == 'imsp' &&
                    array_search($temp['name'], $found_shares) === false) {
                        $result = $share_obj->removeShare($share);
                        if (is_a($result, 'PEAR_Error')) {
                            return $result;
                        }
                        $return['removed'][] = $share->getName();
                }
            }
        }
        return $return;
    }

    /**
     * Creates a Horde_Share for an *existing* IMSP address book.
     * Needed for creating shares for address books created outside
     * of Horde.
     *
     * @param Horde_Share  The share object to create the new share with.
     * @param array        Parameters for the share
     *
     * @return mixed  True | PEAR_Error
     */
    function _createShare($share_obj, $params, $shareparams)
    {
        $share = $share_obj->newShare($GLOBALS['registry']->getAuth(), $params['uid'], $params['name']);
        if (is_a($share, 'PEAR_Error')) {
            return $share;
        }
        $share->set('params', serialize($shareparams));
        Horde_Imsp_Utils::_setPerms($share, $params['acl']);
        $share->save();
        return true;
    }

    /**
     * Determine if we are the owner of the address book.
     * Assumes ownership if username is beginning address book name or
     * if user has admin rights ('a') in acl.
     *
     * @param array $params  Parameters to check for ownership.
     *
     * @return boolean  True if $user is owner, otherwise false.
     */
    function _isOwner($bookName, $username, $acl)
    {
        if (strpos($bookName, $username) === 0) {
            return true;
        } elseif (strpos($acl, 'a')) {
            return true;
        }
        return false;
    }

    /**
     * Translates IMSP acl into share permissions and sets them in share.
     *
     * @param Datatree_Object_Share $share  The share to assign perms to
     * @param string $acl                   The IMSP acl string.
     */
    function _setPerms(&$share, $acl)
    {
         $hPerms = 0;
         if (strpos($acl, 'w') !== false) {
             $hPerms |= Horde_Perms::EDIT;
         }
         if (strpos($acl, 'r') !== false) {
             $hPerms |= Horde_Perms::READ;
         }
         if (strpos($acl, 'd') !== false) {
             $hPerms |= Horde_Perms::DELETE;
         }
         if (strpos($acl, 'l') !== false) {
             $hPerms |= Horde_Perms::SHOW;
         }
        $share->addUserPermission($GLOBALS['registry']->getAuth(), $hPerms);
    }

    /**
     * Translates Horde_Share permissions into IMSP acl.
     *
     * @param integer $perms   Horde_Perms style permission bitmask.
     *
     * @return string   An IMSP acl string
     */
    function permsToACL($perms)
    {
        $acl = '';

        if ($perms & Horde_Perms::SHOW) {
            $acl = 'l';
        }
        if ($perms & Horde_Perms::READ) {
            $acl .= 'r';
        }
        if ($perms & Horde_Perms::EDIT) {
            $acl .= 'w';
        }
        if ($perms & Horde_Perms::DELETE) {
            $acl .= 'd';
        }
        return $acl;
    }

    /**
     * Set's an address book's acl on the IMSP server.
     *
     * @param string $book  The address book name to set
     * @param string $name  The user name to set for.
     * @param string $acl   The acl string to set.
     *
     * @return mixed  True | Pear_Error
     */
    function setACL($params, $book, $name, $acl)
    {
        $imsp = &Horde_Imsp::singleton('Book', $params);
        $imsp->init();
        return $imsp->setACL($book, $name, $acl);
    }
}
