<?php


/*
 * Determine if repository specified is in
 * sync with the commit-ID specified.
 *
 * If it is not in sync, exit with error.
 */

function vipgoci_gitrepo_ok(
	$commit_id,
	$local_git_repo
) {

	/*
	 * Check at what revision the local git repository is.
	 *
	 * We do this to make sure the local repository
	 * is actually checked out at the same commit
	 * as the one we are working with.
	 */

	$lgit_head = vipgoci_gitrepo_get_head(
		$local_git_repo
	);


	/*
	 * Check if commit-ID and head are the same, and
	 * return with a status accordingly.
	 */

	if (
		( false !== $commit_id ) &&
		( $commit_id !== $lgit_head )
	) {
		vipgoci_log(
			'Can not use local Git repository, seems not to be in ' .
			'sync with current commit or does not exist',
			array(
				'commit_id'		=> $commit_id,
				'local_git_repo'	=> $local_git_repo,
				'local_git_repo_head'	=> $lgit_head,
			)
		);

		exit ( VIPGOCI_EXIT_USAGE_ERROR );

	}

	return true;
}


/*
 * Get latest commit HEAD in the specified repository.
 * Will return a commit-hash if successful. Note that
 * this function will execute git.
 */

function vipgoci_gitrepo_get_head( $local_git_repo ) {

	/*
	 * Prepare to execute git; ask git to
	 * operate within a certain path ( -C param ),
	 * to fetch log (one line), and print only
	 * the hash-ID. Catch anything returned to STDERR.
	 */

	$cmd = sprintf(
		'%s -C %s log -n %s --pretty=format:"%s" 2>&1',
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo ),
		escapeshellarg( 1 ),
		escapeshellarg( '%H' )
	);

	/* Actually execute */
	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_cli' );

	$result = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_cli' );

	/*
	 * Trim any whitespace characters away
	 */
	if ( false !== $result ) {
		$result = trim(
			$result
		);

		$result = trim(
			$result,
			"'\""
		);
	}

	return $result;
}

/*
 * Get the current branch of the git-repository.
 */

function vipgoci_gitrepo_branch_current_get( $local_git_repo ) {
	$cmd = sprintf(
		'%s -C %s branch 2>&1',
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo ),
	);

	/* Actually execute */
	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_cli' );

	$results = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_cli' );

	/*
	 * Split results into array
	 */
	$results = explode(
		"\n",
		$results
	);

	/*
	 * Filter away any branch-names that are not active.
	 */
	$results = array_filter(
		$results,
		function( $line ) {
			if ( false === strpos(
				$line,
				'*'
			) ) {
				return false;
			}

			return true;
		}
	);

	/*
	 * Remove spaces and "*" from
	 * the last branch-name, which is the
	 * active one if there at all.
	 */
	$results = array_map(
		function( $line ) {
			return str_replace(
				array(
					" ",
					"*",
				),
				array(
					"",
					"",
				),
				$line
			);
		},
		$results
	);

	/*
	 * Recreate array with
	 * new index keys.
	 */
	$results = array_values(
		$results
	);

	if ( ! empty(
		$results
	) ) {
		return $results[0];
	}

	else {
		return null;
	}
}

/*
 * Fetch "tree" of the repository; a tree
 * of files that are part of the commit
 * specified.
 *
 * Allows filtering out files that the
 * caller does only want to see.
 */

function vipgoci_gitrepo_fetch_tree(
	$options,
	$commit_id,
	$filter = null
) {

	/* Check for cached version */
	$cached_id = array(
		__FUNCTION__, $options['repo-owner'], $options['repo-name'],
		$commit_id, $options['token'], $filter
	);

	$cached_data = vipgoci_cache( $cached_id );

	vipgoci_log(
		'Fetching tree info' .
			( $cached_data ? ' (cached)' : '' ),

		array(
			'repo_owner' => $options['repo-owner'],
			'repo_name' => $options['repo-name'],
			'commit_id' => $commit_id,
			'filter' => $filter,
		)
	);

	if ( false !== $cached_data ) {
		return $cached_data;
	}


	/*
	 * Use local git repository
	 */

	vipgoci_gitrepo_ok(
		$commit_id,
		$options['local-git-repo']
	);

	// Actually get files
	$files_arr = vipgoci_scandir_git_repo(
		$options['local-git-repo'],
		$filter
	);


	/*
	 * Cache the results and return
	 */
	vipgoci_cache(
		$cached_id,
		$files_arr
	);

	return $files_arr;
}


/*
 * Fetch from the local git-repository a particular file
 * which is a part of a commit. Will return the file (raw),
 * or false on error.
 */

function vipgoci_gitrepo_fetch_committed_file(
	$repo_owner,
	$repo_name,
	$github_token,
	$commit_id,
	$file_name,
	$local_git_repo
) {

	vipgoci_gitrepo_ok(
		$commit_id, $local_git_repo
	);

	vipgoci_log(
		'Fetching file-contents from local Git repository',
		array(
			'repo_owner'		=> $repo_owner,
			'repo_name'		=> $repo_name,
			'commit_id'		=> $commit_id,
			'filename'		=> $file_name,
			'local_git_repo'	=> $local_git_repo,
		)
	);


	/*
	 * If everything seems fine, return the file.
	 */

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_repo_fetch_file' );

	$file_contents_tmp = @file_get_contents(
		$local_git_repo . '/' . $file_name
	);

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_repo_fetch_file' );

	return $file_contents_tmp;
}


/*
 * Get 'git blame' log for a particular file,
 * using a local Git repository.
 */

function vipgoci_gitrepo_blame_for_file(
	$commit_id,
	$file_name,
	$local_git_repo
) {
	vipgoci_gitrepo_ok(
		$commit_id, $local_git_repo
	);

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_repo_blame_for_file' );

	vipgoci_log(
		'Fetching \'git blame\' log from Git repository for file',
		array(
			'commmit_id' => $commit_id,
			'file_name' => $file_name,
			'local_git_repo' => $local_git_repo,
		)
	);

	/*
	 * Compose command to get blame-log
	 */

	$cmd = sprintf(
		'%s -C %s blame --line-porcelain %s 2>&1',
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo ),
		escapeshellarg( $file_name )
	);


	/* Actually execute */
	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_cli' );

	$result = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_cli' );

	/*
	 * Process the output from git,
	 * split each line into an array.
	 */

	$blame_log = array();

	$result = explode(
		"\n",
		$result
	);

	$current_commit = array(
	);

	foreach ( $result as $result_line ) {

		/*
		 * First split the line into an array
		 */

		$result_line_arr = explode(
			' ',
			$result_line
		);


		/*
		 * Try to figure out if the line is contains
		 * a commit-ID and line-number, such as this:
		 *
		 * 6c85fe619e39cc7beefb1faf0102d9d872bc7bb2 3 3
		 *
		 * and if so, store them.
		 */

		if (
			( count( $result_line_arr ) >= 3 ) &&
			( strlen( $result_line_arr[0] ) === 40 ) &&
			( ctype_xdigit( $result_line_arr[0] ) === true )
		) {
			$current_commit = array(
				'commit_id'	=> $result_line_arr[0],
				'number'	=> $result_line_arr[1],
			);
		}

		/*
		 * Test if the first string on the line is 'filename',
		 * and if so, store the filename it self. Do so using
		 * a method that will save spaces and so forth in the
		 * filename.
		 */

		else if (
			( count( $result_line_arr ) >= 2 ) &&
			( 'filename' === $result_line_arr[0] )
		) {
			$tmp_file_arr = $result_line_arr;
			unset( $tmp_file_arr[0] );

			$current_commit['filename'] = implode( ' ', $tmp_file_arr );

			unset( $tmp_file_arr );
		}


		/*
		 * If we have got commit-ID, line-number
		 * and filename, we can construct a return array
		 */

		if (
			( ! empty( $current_commit['commit_id'] ) ) &&
			( ! empty( $current_commit['number'] ) ) &&
			( ! empty( $current_commit['filename'] ) )
		) {
			$blame_log[] = array(
				'commit_id' => $current_commit['commit_id'],
				'file_name' => $current_commit['filename'],
				'line_no' => (int) $current_commit['number'],
			);

			$current_commit = array();
		}
	}

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_repo_blame_for_file' );

	return $blame_log;
}

/*
 * Get contents of a particular file at a
 * particular commit-ID from the local git
 * repository.
 */
function vipgoci_gitrepo_get_file_at_commit(
	$commit_id,
	$file_name,
	$local_git_repo,
	$local_git_repo_head_commit_id
) {
	/*
	 * Check if repository is looking good.
	 */
	vipgoci_gitrepo_ok(
		$local_git_repo_head_commit_id,
		$local_git_repo
	);

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_repo_get_file_at_commit' );

	vipgoci_log(
		'Fetching contents of a particular file from the local git repository',
		array(
			'commmit_id'			=> $commit_id,
			'file_name'			=> $file_name,
			'local_git_repo'		=> $local_git_repo,
			'local_git_repo_head_commit_id'	=> $local_git_repo_head_commit_id,
		)
	);

	/*
	 * Compose command to get the file contents.
	 */

	$cmd = sprintf(
		'%s -C %s show %s:%s 2>&1',
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo ),
		escapeshellarg( $commit_id ),
		escapeshellarg( $file_name )
	);

	/* Actually execute */
	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_cli' );

	$result = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_cli' );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_repo_get_file_at_commit' );

	/*
	 * If the file did not exist in
	 * this revision, return null.
	 */
	if ( strpos(
		$result,
		'fatal: Path '
	) === 0 ) {
		return null;
	}

	return $result;
}

/*
 * Initialise submodules for the given local git repository.
 */
function vipgoci_gitrepo_submodules_setup( $local_git_repo ) {
	$cmd = sprintf(
		'%s -C %s submodule init && %s -C %s submodule update 2>&1',
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo ),
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo )
	);

	/* Actually execute */
	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_cli' );

	$result = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_cli' );

	return $result;
}

/*
 * Get submodules for the given local git repository.
 */
function vipgoci_gitrepo_submodules_list( $local_git_repo ) {
	/* Check for cached version */
	$cached_id = array(
		__FUNCTION__, $local_git_repo
	);

	$cached_data = vipgoci_cache( $cached_id );

	if ( false !== $cached_data ) {
		/* Found cached version, return it. */
		return $cached_data;
	}

	/*
	 * No cached version found, get submodule list,
	 * process and return.
	 */

	$cmd = sprintf(
		'%s -C %s submodule 2>&1',
		escapeshellcmd( 'git' ),
		escapeshellarg( $local_git_repo ),
	);

	/* Actually execute */
	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'git_cli' );

	$result = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'git_cli' );

	$result = explode(
		"\n",
		$result
	);

	/*
	 * Clean up results, remove whitespace etc.
	 */
	$result = array_map(
		function( $str ) {
			$str = trim(
				$str
			);

			$arr = explode(
				' ',
				$str
			);

			if ( count( $arr ) !== 3 ) {
				return array();
			}

			$arr[2] = trim(
				$arr[2],
				'()'
			);

			return array(
				'commit_id'		=> $arr[0],
				'submodule_path'	=> $arr[1],
				'submodule_tag'		=> $arr[2],
			);
		},
		$result
	);

	/*
	 * Remove any array items that are not
	 * of correct size.
	 */
	$result = array_filter(
		$result,
		function( $arr_item ) {
			if ( count( $arr_item ) === 3 ) {
				return true;
			}

			return false;
		}
	);

	/*
	 * Cache result.
	 */
	vipgoci_cache(
		$cached_id,
		$result
	);
	
	return $result;
}

/*
 * Get submodule path for the given file,
 * if is a submodule.
 */
function vipgoci_gitrepo_submodule_file_path_get(
	$local_git_repo,
	$file_path
) {
	$submodules_list = vipgoci_gitrepo_submodules_list(
		$local_git_repo
	);

	foreach(
		$submodules_list as $submodule_item
	) {
		if ( strpos(
			$file_path,
			$submodule_item['submodule_path'] . '/'
		) === 0 ) {
			return $submodule_item;
		}
	}

	return null;	
}



/*
 * Get URL for submodule from repository config.
 */
function vipgoci_gitrepo_submodule_get_url(
	$local_git_repo,
	$submodule_path
) {
	/* Check for cached version */
	$cached_id = array(
		__FUNCTION__, $local_git_repo,
		$submodule_path
	);

	$cached_data = vipgoci_cache( $cached_id );

	vipgoci_log(
		'Fetching GitHub repository URL for submodule' .
			vipgoci_cached_indication_str( $cached_data ),
		array(
			'local-git-repo'	=> $local_git_repo,
			'submodule_path'	=> $submodule_path,
		)
	);

	if ( false !== $cached_data ) {
		/* Found cached version, return it. */
		return $cached_data;
	}

	$git_modules_parsed = parse_ini_file(
		$local_git_repo . '/.gitmodules',
		true
	);

	if ( false === $git_modules_parsed ) {
		return null;
	}

	$ret_val = null;

	foreach(
		$git_modules_parsed as
			$git_module_folder => $git_module_info
	) {
		if ( $git_module_info['path'] === $submodule_path ) {
			$dot_git_pos = strrpos(
				$git_module_info['url'],
				'.git'
			);

			$ret_val = $git_module_info['url'];

			if ( false !== $dot_git_pos ) {
				$ret_val = substr(
					$ret_val,
					0,
					$dot_git_pos
				);
			}

			break;
		}
	}

	vipgoci_cache(
		$cached_id,
		$ret_val
	);

	vipgoci_log(
		'Fetched Github repository URL',
		array(
			'submodule_path'	=> $submodule_path,
			'submodule_git_url'	=> $ret_val,
		)
	);

	return $ret_val;
}

