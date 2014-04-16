<?php

namespace Controller;

class User extends Base {

	public function index($f3, $params) {
		$this->_requireLogin();
		$f3->reroute("/user/account");
	}

	public function dashboard($f3, $params) {
		$user_id = $this->_requireLogin();
		$projects = new \Model\Issue\Detail();

		// Add user's group IDs to owner filter
		$owner_ids = array($user_id);
		$groups = new \Model\User\Group();
		foreach($groups->find(array("user_id = ?", $user_id)) as $r) {
			$owner_ids[] = $r->group_id;
		}
		$owner_ids = implode(",", $owner_ids);

		$order = "priority DESC, has_due_date ASC, due_date ASC";
		$f3->set("projects", $projects->paginate(
			0, 50,
			array(
				"owner_id IN ($owner_ids) and type_id=:type AND deleted_date IS NULL AND closed_date IS NULL AND status_closed = 0",
				":type" => $f3->get("issue_type.project"),
			),array(
				"order" => $order
			)
		));

		$bugs = new \Model\Issue\Detail();
		$f3->set("bugs", $bugs->paginate(
			0, 50,
			array(
				"owner_id IN ($owner_ids) and type_id=:type AND deleted_date IS NULL AND closed_date IS NULL AND status_closed = 0",
				":type" => $f3->get("issue_type.bug"),
			),array(
				"order" => $order
			)
		));

		$tasks = new \Model\Issue\Detail();
		$f3->set("tasks", $tasks->paginate(
			0, 100,
			array(
				"owner_id IN ($owner_ids) AND type_id=:type AND deleted_date IS NULL AND closed_date IS NULL AND status_closed = 0",
				":type" => $f3->get("issue_type.task"),
			),array(
				"order" => $order
			)
		));

		$f3->set("menuitem", "index");
		echo \Template::instance()->render("user/dashboard.html");
	}

	public function account($f3, $params) {
		$this->_requireLogin();
		$f3->set("title", "My Account");
		$f3->set("menuitem", "user");
		echo \Template::instance()->render("user/account.html");
	}

	public function save($f3, $params) {
		$id = $this->_requireLogin();

		$f3 = \Base::instance();
		$post = array_map("trim", $f3->get("POST"));

		$user = new \Model\User();
		$user->load($id);

		if(!empty($post["old_pass"])) {

			$security = \Helper\Security::instance();

			// Update password
			if($security->hash($post["old_pass"], $user->salt) == $user->password) {
				if(strlen($post["new_pass"]) >= 6) {
					$user->salt = $security->salt();
					$user->password = $security->hash($post["new_pass"], $user->salt);
					$f3->set("success", "Password updated successfully.");
				} else {
					$f3->set("error", "New password must be at least 6 characters.");
				}
			} else {
				$f3->set("error", "Current password entered is not valid.");
			}

		} else {

			// Update profile
			if(!empty($post["name"])) {
				$user->name = filter_var($post["name"], FILTER_SANITIZE_STRING);
			} else {
				$error = "Please enter a name.";
			}
			if(filter_var($post["email"], FILTER_VALIDATE_EMAIL)) {
				$user->email = $post["email"];
			} else {
				$error = "Please enter a valid email address.";
			}
			if(empty($error) && ctype_xdigit(ltrim($post["task_color"], "#"))) {
				$user->task_color = ltrim($post["task_color"], "#");
			} else {
				$error = "Please enter a valid 6-hexit color code.";
			}

			if(empty($error)) {
				$f3->set("success", "Profile updated successfully.");
			} else {
				$f3->set("error", $error);
			}

		}

		$user->save();
		$f3->set("title", "My Account");
		$f3->set("menuitem", "user");
		echo \Template::instance()->render("user/account.html");
	}

	public function avatar($f3, $params) {
		$id = $this->_requireLogin();
		$f3 = \Base::instance();
		$post = array_map("trim", $f3->get("POST"));

		$user = new \Model\User();
		$user->load($id);
		if(!$user->id) {
			$f3->error(404);
			return;
		}

		$web = \Web::instance();

		$f3->set("UPLOADS",'uploads/avatars/');
		if(!is_dir($f3->get("UPLOADS"))) {
			mkdir($f3->get("UPLOADS"), 0777, true);
		}
		$overwrite = false;
		$slug = true;

		//Make a good name
		$parts = pathinfo($_FILES['avatar']['name']);
		$_FILES['avatar']['name'] = $user->id . "-" . substr(sha1($user->id), 0, 4)  . "." . $parts["extension"];
		$f3->set("avatar_filename", $_FILES['avatar']['name']);

		$files = $web->receive(
			function($file) use($f3, $user) {
				if($file['size'] > $f3->get("files.maxsize")) {
					return false;
				}

				$user->avatar_filename = $f3->get("avatar_filename");
				$user->save();
				return true;
			},
			$overwrite,
			$slug
		);

		// Clear cached profile picture data
		$cache = \Cache::instance();
		$cache->clear($f3->hash("GET /avatar/48/{$user->id}.png") . ".url");
		$cache->clear($f3->hash("GET /avatar/96/{$user->id}.png") . ".url");
		$cache->clear($f3->hash("GET /avatar/128/{$user->id}.png") . ".url");


		$f3->reroute("/user/account");
	}


	public function single($f3, $params) {
		$this->_requireLogin();

		$user = new \Model\User;
		$user->load(array("username = ? AND deleted_date IS NULL AND role != 'group'", $params["username"]));

		if($user->id) {
			$f3->set("title", $user->name);
			$f3->set("this_user", $user);

			$issue = new \Model\Issue\Detail;
			$issues = $issue->paginate(0, 100, array("closed_date IS NULL AND (owner_id = ? OR author_id = ?)", $user->id, $user->id));
			$f3->set("issues", $issues);

			echo \Template::instance()->render("user/single.html");
		} else {
			$f3->error(404);
		}
	}

}
