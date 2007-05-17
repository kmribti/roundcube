-- RoundCube Webmail initial database structure
-- Version 0.1-rc1
-- 

-- --------------------------------------------------------

-- 
-- Table structure for table `cache`
-- 

CREATE TABLE cache (
  cache_id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL default 0,
  session_id varchar(40) default NULL,
  cache_key varchar(128) NOT NULL default '',
  created datetime NOT NULL default '0000-00-00 00:00:00',
  data longtext NOT NULL
);

CREATE INDEX ix_cache_user_id ON cache(user_id);
CREATE INDEX ix_cache_cache_key ON cache(cache_key);
CREATE INDEX ix_cache_session_id ON cache(session_id);


-- --------------------------------------------------------

-- 
-- Table structure for table contacts
-- 

CREATE TABLE contacts (
  contact_id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL default '0',
  changed datetime NOT NULL default '0000-00-00 00:00:00',
  del tinyint NOT NULL default '0',
  name varchar(128) NOT NULL default '',
  email varchar(128) NOT NULL default '',
  firstname varchar(128) NOT NULL default '',
  surname varchar(128) NOT NULL default '',
  vcard text NOT NULL default ''
);

CREATE INDEX ix_contacts_user_id ON contacts(user_id);

-- --------------------------------------------------------

-- 
-- Table structure for table identities
-- 

CREATE TABLE identities (
  identity_id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL default '0',
  del tinyint NOT NULL default '0',
  standard tinyint NOT NULL default '0',
  name varchar(128) NOT NULL default '',
  organization varchar(128) default '',
  email varchar(128) NOT NULL default '',
  "reply-to" varchar(128) NOT NULL default '',
  bcc varchar(128) NOT NULL default '',
  signature text NOT NULL default '',
  html_signature tinyint NOT NULL default '0'
);

CREATE INDEX ix_identities_user_id ON identities(user_id);


-- --------------------------------------------------------

-- 
-- Table structure for table users
-- 

CREATE TABLE users (
  user_id integer NOT NULL PRIMARY KEY,
  username varchar(128) NOT NULL default '',
  mail_host varchar(128) NOT NULL default '',
  alias varchar(128) NOT NULL default '',
  created datetime NOT NULL default '0000-00-00 00:00:00',
  last_login datetime NOT NULL default '0000-00-00 00:00:00',
  language varchar(5) NOT NULL default 'en',
  preferences text NOT NULL default ''
);


-- --------------------------------------------------------

-- 
-- Table structure for table session
-- 

CREATE TABLE session (
  sess_id varchar(40) NOT NULL PRIMARY KEY,
  created datetime NOT NULL default '0000-00-00 00:00:00',
  changed datetime NOT NULL default '0000-00-00 00:00:00',
  ip varchar(15) NOT NULL default '',
  vars text NOT NULL
);


-- --------------------------------------------------------

-- 
-- Table structure for table messages
-- 

CREATE TABLE messages (
  message_id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL default '0',
  del tinyint NOT NULL default '0',
  cache_key varchar(128) NOT NULL default '',
  created datetime NOT NULL default '0000-00-00 00:00:00',
  idx integer NOT NULL default '0',
  uid integer NOT NULL default '0',
  subject varchar(255) NOT NULL default '',
  "from" varchar(255) NOT NULL default '',
  "to" varchar(255) NOT NULL default '',
  cc varchar(255) NOT NULL default '',
  date datetime NOT NULL default '0000-00-00 00:00:00',
  size integer NOT NULL default '0',
  headers text NOT NULL,
  structure text
);

CREATE INDEX ix_messages_user_id ON messages(user_id);
CREATE INDEX ix_messages_cache_key ON messages(cache_key);
CREATE INDEX ix_messages_idx ON messages(idx);
CREATE INDEX ix_messages_uid ON messages(uid);
