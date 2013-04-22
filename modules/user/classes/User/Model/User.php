<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2013 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class User_Model_User extends ORM implements IdentityProvider_UserDefinition {
  protected $password_length = null;

  public function __set($column, $value) {
    switch ($column) {
    case "hashed_password":
      $column = "password";
      break;

    case "password":
      $this->password_length = strlen($value);
      $value = User::hash_password($value);
      break;
    }
    parent::__set($column, $value);
  }

  /**
   * @see ORM::delete()
   */
  public function delete() {
    $old = clone $this;
    Module::event("user_before_delete", $this);
    parent::delete();
    Module::event("user_deleted", $old);

    return $this;
  }

  /**
   * Return a url to the user's avatar image.
   * @param integer $size the target size of the image (default 80px)
   * @return string a url
   */
  public function avatar_url($size=80, $default=null) {
    return sprintf("http://www.gravatar.com/avatar/%s.jpg?s=%d&r=pg%s",
                   md5($this->email), $size, $default ? "&d=" . urlencode($default) : "");
  }

  public function groups() {
    return $this->groups->find_all()->as_array();
  }

  /**
   * Specify our rules here so that we have access to the instance of this model.
   */
  public function validate(Validation $array=null) {
    // validate() is recursive, only modify the rules on the outermost call.
    if (!$array) {
      $this->rules = array(
        "admin"     => array("callbacks" => array(array($this, "valid_admin"))),
        "email"     => array("rules"     => array("length[1,255]", "Valid::email"),
                             "callbacks" => array(array($this, "valid_email"))),
        "full_name" => array("rules"     => array("length[0,255]")),
        "locale"    => array("rules"     => array("length[2,10]")),
        "name"      => array("rules"     => array("length[1,32]", "required"),
                             "callbacks" => array(array($this, "valid_name"))),
        "password"  => array("callbacks" => array(array($this, "valid_password"))),
        "url"       => array("rules"     => array("Valid::url")),
      );
    }

    parent::validate($array);
  }

  /**
   * Handle any business logic necessary to save (i.e. create or update) a user.
   * @see ORM::save()
   *
   * @return ORM Model_User
   */
  public function save(Validation $validation=null) {
    if ($this->full_name === null) {
      $this->full_name = "";
    }

    return parent::save($validation);
  }

  /**
   * Handle any business logic necessary to create a user.
   * @see ORM::create()
   *
   * @return ORM Model_User
   */
  public function create(Validation $validation=null) {
    Module::event("user_before_create");

    parent::create($validation);

    $this->add("groups", Group::everybody());
    if (!$this->guest) {
      $this->add("groups", Group::registered_users());
    }

    Module::event("user_created", $this);

    return $this;
  }

  /**
   * Handle any business logic necessary to update a user.
   * @see ORM::update()
   *
   * @return ORM Model_User
   */
  public function update(Validation $validation=null) {
    Module::event("user_before_update");
    $original = ORM::factory("User", $this->id);
    parent::update();
    Module::event("user_updated", $original, $this);

    return $this;
  }

  /**
   * Return the best version of the user's name.  Either their specified full name, or fall back
   * to the user name.
   * @return string
   */
  public function display_name() {
    return empty($this->full_name) ? $this->name : $this->full_name;
  }

  /**
   * Validate the user name.  Make sure there are no conflicts.
   */
  public function valid_name(Validation $v, $field) {
    if (DB::select()->from("users")
        ->where("name", "=", $this->name)
        ->merge_where($this->id ? array(array("id", "<>", $this->id)) : null)
        ->execute()->count() == 1) {
      $v->add_error("name", "conflict");
    }
  }

  /**
   * Validate the password.
   */
  public function valid_password(Validation $v, $field) {
    if ($this->guest) {
      return;
    }

    if (!$this->loaded() || isset($this->password_length)) {
      $minimum_length = Module::get_var("user", "minimum_password_length", 5);
      if ($this->password_length < $minimum_length) {
        $v->add_error("password", "min_length");
      }
    }
  }

  /**
   * Validate the admin bit.
   */
  public function valid_admin(Validation $v, $field) {
    $active = Identity::active_user();
    if ($this->id == $active->id && $active->admin && !$this->admin) {
      $v->add_error("admin", "locked");
    }
  }

  /**
   * Validate the email field.
   */
  public function valid_email(Validation $v, $field) {
    if ($this->guest) {  // guests don't require an email address
      return;
    }

    if (empty($this->email)) {
      $v->add_error("email", "required");
    }
  }
}
